<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Read-only admin payment-transaction listing/detail backing the refund
 * console. Safe projection only; computed refunded/remaining totals come
 * from the ledger sum.
 */
class AdminPaymentListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function makeTransaction(array $overrides = []): PaymentTransaction
    {
        $student = $overrides['user'] ?? User::factory()->create(['role' => 'student']);
        $title = 'Course ' . Str::random(6);
        $course = $overrides['course'] ?? Course::create([
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(4),
            'description' => 'Payment list fixture.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => 100.00,
        ]);

        return PaymentTransaction::create(array_merge([
            'provider' => 'stub',
            'order_id' => 'order_' . Str::random(8),
            'payment_id' => 'pay_' . Str::random(8),
            'idempotency_key' => 'list-key-' . Str::random(8),
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount_paise' => 100000,
            'currency' => 'INR',
            'status' => 'paid',
            'paid_at' => now(),
        ], $overrides));
    }

    private function list(array $params = [])
    {
        $query = http_build_query($params);

        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/payments' . ($query !== '' ? '?' . $query : ''));
    }

    public function test_admin_can_list_transactions_paginated(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->makeTransaction();
        }

        $this->list()->assertOk()
            ->assertJsonPath('per_page', 15)
            ->assertJsonPath('total', 20)
            ->assertJsonCount(15, 'data');
    }

    public function test_per_page_bounds(): void
    {
        $this->makeTransaction();

        $this->list(['per_page' => 5])->assertOk()->assertJsonCount(1, 'data');
        $this->list(['per_page' => 0])->assertOk()->assertJsonPath('per_page', 15);
        $this->list(['per_page' => 500])->assertOk()->assertJsonPath('per_page', 100);
    }

    public function test_ordering_is_id_descending(): void
    {
        $first = $this->makeTransaction();
        $second = $this->makeTransaction();

        $ids = $this->list()->assertOk()->json('data.*.id');

        $this->assertSame([$second->id, $first->id], $ids);
    }

    public function test_filters_status_provider_search(): void
    {
        $paid = $this->makeTransaction(['status' => 'paid']);
        $this->makeTransaction(['status' => 'failed', 'provider' => 'razorpay']);

        $this->list(['status' => 'paid'])->assertOk()->assertJsonPath('total', 1);
        $this->list(['provider' => 'razorpay'])->assertOk()->assertJsonPath('total', 1);
        $this->list(['search' => substr($paid->order_id, 0, 12)])->assertOk()->assertJsonPath('total', 1);
        $this->list(['search' => 'no-such-payment'])->assertOk()->assertJsonPath('total', 0);
    }

    public function test_computed_refunded_and_remaining(): void
    {
        $tx = $this->makeTransaction(['amount_paise' => 100000]);

        PaymentRefund::create([
            'payment_transaction_id' => $tx->id,
            'provider' => 'stub',
            'provider_refund_id' => 'stub_rfnd_list_1',
            'idempotency_key' => 'list-refund-1',
            'initiated_by' => $this->admin->id,
            'amount_paise' => 30000,
            'currency' => 'INR',
            'status' => PaymentRefund::STATUS_SUCCEEDED,
            'source' => PaymentRefund::SOURCE_ADMIN,
        ]);

        $item = $this->list()->assertOk()->json('data.0');

        $this->assertSame(30000, $item['refunded_amount']);
        $this->assertSame(70000, $item['remaining_refundable']);
    }

    public function test_list_exposes_no_secrets(): void
    {
        $this->makeTransaction();

        $body = $this->list()->assertOk()->getContent();

        foreach (['idempotency_key', 'metadata', 'secret', 'authorization', 'trace', 'Exception'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }

        $item = $this->list()->assertOk()->json('data.0');
        $this->assertArrayHasKey('user', $item);
        $this->assertArrayHasKey('course', $item);
        $this->assertSame(['id', 'name', 'email'], array_keys($item['user']));
    }

    public function test_authorization(): void
    {
        $this->makeTransaction();

        // Unauthenticated first: actingAs persists for later requests in
        // the same test.
        $this->getJson('/api/admin/payments')->assertStatus(401);

        $this->actingAs(User::factory()->create(['role' => 'student']), 'sanctum')
            ->getJson('/api/admin/payments')
            ->assertStatus(403);

        $this->actingAs(User::factory()->create(['role' => 'tutor']), 'sanctum')
            ->getJson('/api/admin/payments')
            ->assertStatus(403);
    }

    public function test_show_returns_transaction_with_refund_history(): void
    {
        $tx = $this->makeTransaction();

        PaymentRefund::create([
            'payment_transaction_id' => $tx->id,
            'provider' => 'stub',
            'provider_refund_id' => 'stub_rfnd_show_2',
            'idempotency_key' => 'show-key-2',
            'initiated_by' => $this->admin->id,
            'amount_paise' => 10000,
            'currency' => 'INR',
            'status' => PaymentRefund::STATUS_SUCCEEDED,
            'source' => PaymentRefund::SOURCE_ADMIN,
        ]);
        PaymentRefund::create([
            'payment_transaction_id' => $tx->id,
            'provider' => 'stub',
            'provider_refund_id' => 'stub_rfnd_show_1',
            'idempotency_key' => 'show-key-1',
            'initiated_by' => $this->admin->id,
            'amount_paise' => 20000,
            'currency' => 'INR',
            'status' => PaymentRefund::STATUS_SUCCEEDED,
            'source' => PaymentRefund::SOURCE_ADMIN,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/payments/{$tx->id}")
            ->assertOk();

        $payment = $response->json('payment');

        $this->assertSame($tx->id, $payment['id']);
        $this->assertSame(30000, $payment['refunded_amount']);
        $this->assertSame(70000, $payment['remaining_refundable']);
        // Newest first.
        $this->assertSame(['stub_rfnd_show_1', 'stub_rfnd_show_2'], array_column($payment['refunds'], 'provider_refund_id'));
    }

    public function test_show_unknown_transaction_is_safe_404(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/payments/999999');

        $response->assertStatus(404);
        $this->assertStringNotContainsString('App\\Models', (string) $response->getContent());
    }
}
