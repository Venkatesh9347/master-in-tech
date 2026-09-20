<?php

namespace Tests\Feature;

use App\Jobs\SendWebhookDeliveryJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\WebhookDispatcherService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 9 dispatcher: signing, fan-out, retry ledger, job guards.
 *
 * Portable across SQLite/MySQL/PostgreSQL: no PRAGMA, no engine-specific
 * error text, no hardcoded driver names.
 */
class WebhookDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(array $overrides = []): WebhookSubscription
    {
        return WebhookSubscription::create(array_merge([
            'target_url' => 'https://hooks.example.com/deliveries',
            'secret' => str_repeat('s', 32),
            'events' => [WebhookDispatcherService::EVENT_PAYMENT_PAID],
            'is_active' => true,
        ], $overrides));
    }

    public function test_signature_is_deterministic_and_verifiable(): void
    {
        $secret = str_repeat('k', 32);
        $timestamp = (string) (time() - 10);
        $body = '{"payment_id":"pay_1"}';

        $this->assertSame(
            WebhookDispatcherService::sign($secret, $timestamp, $body),
            WebhookDispatcherService::sign($secret, $timestamp, $body)
        );
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            WebhookDispatcherService::sign($secret, $timestamp, $body)
        );

        $signature = WebhookDispatcherService::sign($secret, $timestamp, $body);

        $this->assertTrue(WebhookDispatcherService::verify($secret, $timestamp, $body, $signature));
        $this->assertFalse(WebhookDispatcherService::verify($secret, $timestamp, '{"payment_id":"pay_2"}', $signature));
        $this->assertFalse(WebhookDispatcherService::verify(str_repeat('x', 32), $timestamp, $body, $signature));
        $this->assertFalse(WebhookDispatcherService::verify($secret, $timestamp, $body, 'deadbeef'));
        $this->assertFalse(WebhookDispatcherService::verify($secret, '', $body, $signature));
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $secret = str_repeat('k', 32);
        $body = '{"payment_id":"pay_1"}';

        $stale = (string) (time() - WebhookDispatcherService::SIGNATURE_TOLERANCE_SECONDS - 60);
        $staleSignature = WebhookDispatcherService::sign($secret, $stale, $body);

        // A stale signature never verifies, even though it was honestly made.
        $this->assertFalse(WebhookDispatcherService::verify($secret, $stale, $body, $staleSignature));

        // Fresh signatures verify deterministically on every attempt (replay
        // within the window resolves to the same verdict every time).
        $fresh = (string) (time() - 5);
        $freshSignature = WebhookDispatcherService::sign($secret, $fresh, $body);
        $this->assertTrue(WebhookDispatcherService::verify($secret, $fresh, $body, $freshSignature));
        $this->assertTrue(WebhookDispatcherService::verify($secret, $fresh, $body, $freshSignature));
    }

    public function test_dispatch_fans_out_only_to_matching_active_subscriptions(): void
    {
        Http::fake();

        $this->subscription();
        $this->subscription([
            'target_url' => 'https://hooks.example.com/other',
            'events' => [WebhookDispatcherService::EVENT_PAYMENT_REFUNDED],
        ]);
        $this->subscription([
            'target_url' => 'https://hooks.example.com/off',
            'events' => [WebhookDispatcherService::EVENT_PAYMENT_PAID],
            'is_active' => false,
        ]);

        app(WebhookDispatcherService::class)->dispatch(
            WebhookDispatcherService::EVENT_PAYMENT_PAID,
            ['payment_id' => 'pay_1']
        );

        // Exactly one ledger row: the matching active subscription.
        $this->assertSame(1, WebhookDelivery::count());
        $delivery = WebhookDelivery::first();
        $this->assertSame(WebhookDispatcherService::EVENT_PAYMENT_PAID, $delivery->event);
        $this->assertSame(['payment_id' => 'pay_1'], $delivery->payload);

        // Unknown events are ignored without throwing.
        app(WebhookDispatcherService::class)->dispatch('no.such.event', []);
        $this->assertSame(1, WebhookDelivery::count());
    }

    public function test_retry_progression_with_backoff_then_dead_letter(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $frozen = Carbon::parse('2026-06-01 12:00:00');
        Carbon::setTestNow($frozen);

        try {
            $subscription = $this->subscription();

            $delivery = WebhookDelivery::create([
                'webhook_subscription_id' => $subscription->id,
                'event' => WebhookDispatcherService::EVENT_PAYMENT_PAID,
                'payload' => ['payment_id' => 'pay_1'],
                'status' => WebhookDelivery::STATUS_PENDING,
                'attempts' => 0,
            ]);

            $dispatcher = app(WebhookDispatcherService::class);
            $expectedDelays = [60, 300, 900, 3600];

            foreach ($expectedDelays as $index => $delay) {
                $this->assertFalse($dispatcher->attemptDelivery($delivery));

                $fresh = $delivery->fresh();
                $this->assertSame(WebhookDelivery::STATUS_FAILED, $fresh->status);
                $this->assertSame($index + 1, (int) $fresh->attempts);
                $this->assertSame(
                    $frozen->copy()->addSeconds($delay)->toDateTimeString(),
                    $fresh->next_retry_at->format('Y-m-d H:i:s')
                );
                $this->assertNotEmpty($fresh->last_error);
            }

            // Fifth failure dead-letters instead of scheduling another retry.
            $this->assertFalse($dispatcher->attemptDelivery($delivery));

            $dead = $delivery->fresh();
            $this->assertSame(WebhookDelivery::STATUS_DEAD, $dead->status);
            $this->assertSame(5, (int) $dead->attempts);
            $this->assertNull($dead->next_retry_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_successful_retry_marks_delivered(): void
    {
        // Note: Http::fake() stubs are first-match-wins, so a response
        // sequence is used instead of re-faking mid-test.
        Http::fakeSequence()
            ->push(null, 500)
            ->push(null, 500)
            ->push(['ok' => true], 200);

        $subscription = $this->subscription();

        $delivery = WebhookDelivery::create([
            'webhook_subscription_id' => $subscription->id,
            'event' => WebhookDispatcherService::EVENT_PAYMENT_PAID,
            'payload' => ['payment_id' => 'pay_1'],
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
        ]);

        $dispatcher = app(WebhookDispatcherService::class);

        $this->assertFalse($dispatcher->attemptDelivery($delivery));
        $this->assertFalse($dispatcher->attemptDelivery($delivery));
        $this->assertTrue($dispatcher->attemptDelivery($delivery));

        $fresh = $delivery->fresh();
        $this->assertSame(WebhookDelivery::STATUS_DELIVERED, $fresh->status);
        $this->assertSame(3, (int) $fresh->attempts);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertNull($fresh->last_error);
    }

    public function test_redirect_responses_are_not_followed(): void
    {
        // S1: the redirect target answers 200 on purpose — if redirects were
        // followed, the delivery would (wrongly) succeed and two requests
        // would be recorded.
        Http::fake([
            '*hooks.example.com/r*' => Http::response(null, 302, ['Location' => 'http://169.254.169.254/x']),
            '*169.254.169.254*' => Http::response(['ok' => true], 200),
        ]);

        $subscription = $this->subscription(['target_url' => 'https://hooks.example.com/r']);

        $delivery = WebhookDelivery::create([
            'webhook_subscription_id' => $subscription->id,
            'event' => WebhookDispatcherService::EVENT_PAYMENT_PAID,
            'payload' => ['payment_id' => 'pay_1'],
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
        ]);

        $this->assertFalse(app(WebhookDispatcherService::class)->attemptDelivery($delivery));

        $fresh = $delivery->fresh();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $fresh->status);
        $this->assertSame(1, (int) $fresh->attempts);

        // Exactly one request total: the internal redirect destination must
        // never be requested.
        Http::assertSentCount(1);
    }

    public function test_job_declares_five_attempt_execution_policy(): void
    {
        // C1: this payload-level policy takes precedence over the worker's
        // global --tries flag (e.g. the shipped --tries=3), so all five
        // ledger attempts execute and the fifth failure dead-letters (see
        // test_retry_progression_with_backoff_then_dead_letter) with no
        // sixth attempt.
        $job = new SendWebhookDeliveryJob(123);

        $this->assertSame(5, $job->tries);
    }

    public function test_job_skips_terminal_deliveries_without_sending(): void
    {
        Http::fake();

        $subscription = $this->subscription();

        $delivery = WebhookDelivery::create([
            'webhook_subscription_id' => $subscription->id,
            'event' => WebhookDispatcherService::EVENT_PAYMENT_PAID,
            'payload' => ['payment_id' => 'pay_1'],
            'status' => WebhookDelivery::STATUS_DELIVERED,
            'attempts' => 1,
        ]);

        (new SendWebhookDeliveryJob($delivery->id))->handle(app(WebhookDispatcherService::class));

        Http::assertNothingSent();
        $this->assertSame(1, WebhookDelivery::count());
    }
}
