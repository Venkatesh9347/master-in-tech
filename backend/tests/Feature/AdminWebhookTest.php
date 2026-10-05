<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 9 admin API: subscription registry + delivery ledger.
 *
 * Portable across SQLite/MySQL/PostgreSQL: no PRAGMA, no engine-specific
 * error text, no hardcoded driver names.
 */
class AdminWebhookTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->student = User::factory()->create(['role' => 'student']);
    }

    private function subscriptionPayload(array $overrides = []): array
    {
        return array_merge([
            'target_url' => 'https://hooks.example.com/deliveries',
            'secret' => str_repeat('s', 32),
            'events' => ['payment.paid'],
            'is_active' => true,
        ], $overrides);
    }

    public function test_admin_can_manage_subscriptions(): void
    {
        $create = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/webhook-subscriptions', $this->subscriptionPayload());

        $create->assertCreated()
            ->assertJsonPath('subscription.target_url', 'https://hooks.example.com/deliveries')
            ->assertJsonPath('subscription.events', ['payment.paid'])
            ->assertJsonPath('subscription.is_active', true);

        // The secret is never serialized back.
        $create->assertJsonMissingPath('subscription.secret');

        $id = $create->json('subscription.id');

        $this->getJson('/api/admin/webhook-subscriptions')
            ->assertOk();

        $show = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/webhook-subscriptions/{$id}");

        $show->assertOk()->assertJsonMissingPath('subscription.secret');

        $update = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/webhook-subscriptions/{$id}", [
                'is_active' => false,
            ]);

        $update->assertOk()->assertJsonPath('subscription.is_active', false);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/admin/webhook-subscriptions/{$id}")
            ->assertOk();

        $this->assertSame(0, WebhookSubscription::where('id', $id)->count());
    }

    public function test_subscription_validation_rejects_bad_events_and_internal_targets(): void
    {
        // Unknown event names are rejected by the allow-list.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/webhook-subscriptions', $this->subscriptionPayload([
                'events' => ['no.such.event'],
            ]))
            ->assertStatus(422);

        // Short secrets are rejected.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/webhook-subscriptions', $this->subscriptionPayload([
                'secret' => 'too-short',
            ]))
            ->assertStatus(422);

        // Internal destinations are rejected (representative set).
        foreach ([
            'http://localhost/hook',
            'http://localhost:8000/hook',
            'http://127.0.0.1/hook',
            'http://127.0.0.1:9000/hook',
            'http://10.0.0.5/hook',
            'http://192.168.1.10/hook',
            'http://169.254.169.254/latest/meta-data/',
            'http://[::1]/hook',
        ] as $target) {
            $this->actingAs($this->admin, 'sanctum')
                ->postJson('/api/admin/webhook-subscriptions', $this->subscriptionPayload([
                    'target_url' => $target,
                ]))
                ->assertStatus(422);
        }

        $this->assertSame(0, WebhookSubscription::count());
    }

    public function test_valid_external_https_target_is_accepted(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/webhook-subscriptions', $this->subscriptionPayload([
                'target_url' => 'https://hooks.example.com/deliveries',
                'events' => ['payment.paid', 'payment.refunded', 'enrollment.created', 'certificate.issued'],
            ]))
            ->assertCreated();

        $this->assertSame(1, WebhookSubscription::count());
    }

    public function test_non_admin_cannot_manage_webhooks(): void
    {
        // Unauthenticated first: actingAs() persists for the rest of the test.
        $this->postJson('/api/admin/webhook-subscriptions', $this->subscriptionPayload())
            ->assertStatus(401);

        $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/admin/webhook-subscriptions', $this->subscriptionPayload())
            ->assertForbidden();

        $this->assertSame(0, WebhookSubscription::count());
    }

    public function test_subscription_mutations_emit_audit_events_without_secrets(): void
    {
        $create = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/webhook-subscriptions', $this->subscriptionPayload());
        $create->assertCreated();
        $id = $create->json('subscription.id');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created_webhook_subscription',
            'auditable_id' => $id,
            'user_id' => $this->admin->id,
        ]);

        $update = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/webhook-subscriptions/{$id}", [
                'is_active' => false,
                'secret' => str_repeat('n', 32),
            ]);
        $update->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'updated_webhook_subscription',
            'auditable_id' => $id,
        ]);

        // Secret values must never appear in the audit trail.
        foreach (\App\Models\AuditLog::where('action', 'updated_webhook_subscription')->get() as $log) {
            $this->assertStringNotContainsString(str_repeat('n', 32), json_encode($log->new_values));
            $this->assertTrue((bool) ($log->new_values['secret_rotated'] ?? false));
        }

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/admin/webhook-subscriptions/{$id}")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'deleted_webhook_subscription',
        ]);
    }

    public function test_admin_can_list_deliveries_and_retry_failed(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $subscription = WebhookSubscription::create([
            'target_url' => 'https://hooks.example.com/deliveries',
            'secret' => str_repeat('s', 32),
            'events' => ['payment.paid'],
            'is_active' => true,
        ]);

        $delivery = WebhookDelivery::create([
            'webhook_subscription_id' => $subscription->id,
            'event' => 'payment.paid',
            'payload' => ['payment_id' => 'pay_x'],
            'status' => WebhookDelivery::STATUS_FAILED,
            'attempts' => 2,
        ]);

        $list = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/webhook-deliveries');

        $list->assertOk();
        $this->assertSame($delivery->id, $list->json('data.0.id'));

        $retry = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/webhook-deliveries/{$delivery->id}/retry");

        $retry->assertOk()->assertJsonPath('delivery.status', 'failed');
        $this->assertSame(1, WebhookDelivery::count());

        // Manual retry resets the cycle (attempts 2 -> 0) and the immediate
        // re-attempt fails once more (attempts 1, still exactly one row).
        $fresh = $delivery->fresh();
        $this->assertSame(1, (int) $fresh->attempts);
        $this->assertNotNull($fresh->next_retry_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'retried_webhook_delivery',
            'auditable_id' => $delivery->id,
        ]);
    }

    public function test_retry_rejects_delivered_and_unauthorized(): void
    {
        $subscription = WebhookSubscription::create([
            'target_url' => 'https://hooks.example.com/deliveries',
            'secret' => str_repeat('s', 32),
            'events' => ['payment.paid'],
            'is_active' => true,
        ]);

        $delivered = WebhookDelivery::create([
            'webhook_subscription_id' => $subscription->id,
            'event' => 'payment.paid',
            'payload' => [],
            'status' => WebhookDelivery::STATUS_DELIVERED,
            'attempts' => 1,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/webhook-deliveries/{$delivered->id}/retry")
            ->assertStatus(422);

        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/admin/webhook-deliveries/{$delivered->id}/retry")
            ->assertForbidden();

        $this->assertSame(1, WebhookDelivery::count());
        $this->assertSame(
            WebhookDelivery::STATUS_DELIVERED,
            $delivered->fresh()->status
        );
    }
}
