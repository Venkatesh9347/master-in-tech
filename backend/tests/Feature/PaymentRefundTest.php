<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payment\Providers\StubPaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P1-A outbound refunds: admin-initiated refunds through the
 * PaymentProviderInterface abstraction (stub provider; no network).
 *
 * Product rule under test: a refund MUST NOT automatically revoke enrollment.
 */
class PaymentRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['payment.default_provider' => 'stub']);
        StubPaymentProvider::resetFailure();
    }

    private function makeCourse(): Course
    {
        $title = 'Course '.Str::random(6);

        return Course::create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(4),
            'description' => 'Refund fixture course.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => 1000.00,
        ]);
    }

    private function makePaidTransaction(int $amountPaise = 100000, string $status = 'paid'): array
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->makeCourse();

        $tx = PaymentTransaction::create([
            'provider' => 'stub',
            'order_id' => 'order_ref_'.Str::random(8),
            'payment_id' => 'pay_ref_'.Str::random(8),
            'idempotency_key' => 'refund-key-'.Str::random(8),
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount_paise' => $amountPaise,
            'currency' => 'INR',
            'status' => $status,
            'paid_at' => now(),
        ]);

        return [$student, $course, $tx];
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function refundUrl(PaymentTransaction $tx): string
    {
        return '/api/admin/payments/'.$tx->id.'/refund';
    }

    /* 1. admin can initiate full refund */

    public function test_admin_can_initiate_full_refund(): void
    {
        $admin = $this->makeAdmin();
        [$student, $course, $tx] = $this->makePaidTransaction();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), []);

        $response->assertStatus(201)
            ->assertJsonPath('refund.amount', 100000)
            ->assertJsonPath('refund.status', 'succeeded')
            ->assertJsonPath('payment.status', 'refunded')
            ->assertJsonPath('payment.remaining_refundable', 0);

        $this->assertSame('refunded', $tx->fresh()->status);
        $this->assertSame(1, PaymentRefund::where('payment_transaction_id', $tx->id)->count());
    }

    /* 2. non-admin cannot initiate refund */

    public function test_non_admin_cannot_initiate_refund(): void
    {
        [$student, $course, $tx] = $this->makePaidTransaction();

        foreach (['student', 'tutor', 'telecaller', 'counsellor'] as $role) {
            $user = $role === 'student' ? $student : User::factory()->create(['role' => $role]);

            $this->actingAs($user, 'sanctum')
                ->postJson($this->refundUrl($tx), ['amount' => 1000])
                ->assertStatus(403);
        }

        $this->assertSame(0, PaymentRefund::count());
        $this->assertSame('paid', $tx->fresh()->status);
    }

    /* 3. unauthenticated request rejected */

    public function test_unauthenticated_request_rejected(): void
    {
        [, , $tx] = $this->makePaidTransaction();

        $this->postJson($this->refundUrl($tx), ['amount' => 1000])
            ->assertStatus(401);

        $this->assertSame(0, PaymentRefund::count());
    }

    /* 4. successful partial refund */

    public function test_successful_partial_refund(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 30000]);

        $response->assertStatus(201)
            ->assertJsonPath('refund.amount', 30000)
            ->assertJsonPath('payment.status', 'partially_refunded')
            ->assertJsonPath('payment.refunded_amount', 30000)
            ->assertJsonPath('payment.remaining_refundable', 70000);

        $this->assertSame('partially_refunded', $tx->fresh()->status);
    }

    /* 5. amount greater than remaining refundable amount rejected */

    public function test_amount_greater_than_remaining_rejected(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 100001])
            ->assertStatus(422)
            ->assertJson(['error' => 'amount_exceeds_remaining']);

        $this->assertSame(0, PaymentRefund::count());
        $this->assertSame('paid', $tx->fresh()->status);
    }

    /* 6. zero amount rejected */

    public function test_zero_amount_rejected(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, PaymentRefund::count());
    }

    /* 7. negative amount rejected */

    public function test_negative_amount_rejected(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => -500])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, PaymentRefund::count());
    }

    /* 8. already fully refunded payment rejected */

    public function test_already_fully_refunded_payment_rejected(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), [])
            ->assertStatus(201);

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), [])
            ->assertStatus(422)
            ->assertJson(['error' => 'already_refunded']);

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 1])
            ->assertStatus(422)
            ->assertJson(['error' => 'already_refunded']);

        $this->assertSame(1, PaymentRefund::where('payment_transaction_id', $tx->id)->count());
    }

    /* 9. provider failure does not create successful refund */

    public function test_provider_failure_does_not_create_successful_refund(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction();
        StubPaymentProvider::failNextRefund();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 50000])
            ->assertStatus(502)
            ->assertJson(['error' => 'provider_failed']);

        $this->assertSame(0, PaymentRefund::where('status', PaymentRefund::STATUS_SUCCEEDED)->count());
        $this->assertSame('paid', $tx->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_refund_failed']);
    }

    /* 10. provider refund ID is persisted */

    public function test_provider_refund_id_is_persisted(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 10000]);

        $response->assertStatus(201);

        $providerRefundId = $response->json('refund.provider_refund_id');
        $this->assertIsString($providerRefundId);
        $this->assertNotSame('', $providerRefundId);

        $this->assertDatabaseHas('payment_refunds', [
            'payment_transaction_id' => $tx->id,
            'provider_refund_id' => $providerRefundId,
            'amount_paise' => 10000,
            'status' => PaymentRefund::STATUS_SUCCEEDED,
        ]);
    }

    /* 11. existing previous refunds reduce remaining refundable amount */

    public function test_existing_previous_refunds_reduce_remaining(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction();

        PaymentRefund::create([
            'payment_transaction_id' => $tx->id,
            'provider' => 'stub',
            'provider_refund_id' => 'stub_rfnd_prior_1',
            'idempotency_key' => 'prior-key-1',
            'initiated_by' => $admin->id,
            'amount_paise' => 20000,
            'currency' => 'INR',
            'status' => PaymentRefund::STATUS_SUCCEEDED,
            'source' => PaymentRefund::SOURCE_ADMIN,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 30000])
            ->assertStatus(201)
            ->assertJsonPath('payment.refunded_amount', 50000)
            ->assertJsonPath('payment.remaining_refundable', 50000);
    }

    /* 12. full refund after previous partial refunds only remaining amount */

    public function test_full_refund_after_partial_refunds_only_remaining_amount(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 20000])
            ->assertStatus(201);

        // Omitted amount = full refund of the REMAINING balance only.
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), []);

        $response->assertStatus(201)
            ->assertJsonPath('refund.amount', 80000)
            ->assertJsonPath('payment.status', 'refunded')
            ->assertJsonPath('payment.remaining_refundable', 0);
    }

    /* Spec arithmetic sequence: 1000 / 200 / 300 -> 500 left; 501 fails; 500 ok; 1 fails */

    public function test_partial_refund_arithmetic_sequence(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction(100000);
        $asAdmin = fn () => $this->actingAs($admin, 'sanctum');

        PaymentRefund::create([
            'payment_transaction_id' => $tx->id,
            'provider' => 'stub',
            'provider_refund_id' => 'stub_rfnd_seq_prior',
            'idempotency_key' => 'seq-prior-key',
            'initiated_by' => $admin->id,
            'amount_paise' => 20000,
            'currency' => 'INR',
            'status' => PaymentRefund::STATUS_SUCCEEDED,
            'source' => PaymentRefund::SOURCE_ADMIN,
        ]);

        $asAdmin()->postJson($this->refundUrl($tx), ['amount' => 30000])
            ->assertStatus(201)
            ->assertJsonPath('payment.remaining_refundable', 50000);

        $asAdmin()->postJson($this->refundUrl($tx), ['amount' => 50001])
            ->assertStatus(422)
            ->assertJson(['error' => 'amount_exceeds_remaining']);

        $asAdmin()->postJson($this->refundUrl($tx), ['amount' => 50000])
            ->assertStatus(201)
            ->assertJsonPath('payment.status', 'refunded');

        $asAdmin()->postJson($this->refundUrl($tx), ['amount' => 1])
            ->assertStatus(422)
            ->assertJson(['error' => 'already_refunded']);

        $this->assertSame(
            100000,
            (int) PaymentRefund::where('payment_transaction_id', $tx->id)
                ->where('status', PaymentRefund::STATUS_SUCCEEDED)
                ->sum('amount_paise')
        );
    }

    /* 13. duplicate logical request is idempotent */

    public function test_duplicate_logical_request_is_idempotent(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction();

        $first = $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 25000, 'idempotency_key' => 'refund-dedupe-1'])
            ->assertStatus(201)
            ->json('refund');

        $second = $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 25000, 'idempotency_key' => 'refund-dedupe-1'])
            ->assertStatus(201)
            ->json('refund');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($first['provider_refund_id'], $second['provider_refund_id']);
        $this->assertSame(1, PaymentRefund::where('payment_transaction_id', $tx->id)->count());
        $this->assertSame(25000, (int) PaymentRefund::where('payment_transaction_id', $tx->id)->sum('amount_paise'));
    }

    /* 14. concurrent refund attempts cannot exceed captured amount.
     *
     * What was tested and why: true OS-level parallelism is not practical
     * with this suite's single-process in-memory SQLite database. Correctness
     * rests on (a) lockForUpdate on the payment row before the remaining
     * amount is calculated and re-checked, and (b) the unique
     * (provider, idempotency_key) backstop. This test exercises the
     * re-check outcome: after one full refund commits, a second simultaneous
     * claim on the same balance is rejected and the ledger never exceeds the
     * captured amount.
     */

    public function test_concurrent_refund_attempts_cannot_exceed_captured_amount(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction(100000);

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 100000, 'idempotency_key' => 'race-a'])
            ->assertStatus(201);

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 100000, 'idempotency_key' => 'race-b'])
            ->assertStatus(422)
            ->assertJson(['error' => 'already_refunded']);

        $this->assertSame(
            100000,
            (int) PaymentRefund::where('payment_transaction_id', $tx->id)
                ->where('status', PaymentRefund::STATUS_SUCCEEDED)
                ->sum('amount_paise')
        );
    }

    /* 15. audit event is recorded */

    public function test_audit_event_is_recorded(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 10000, 'reason' => 'Duplicate charge'])
            ->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_refund_initiated',
            'user_id' => $admin->id,
            'auditable_type' => PaymentTransaction::class,
            'auditable_id' => $tx->id,
        ]);
    }

    /* 16. enrollment is NOT automatically revoked */

    public function test_enrollment_is_not_automatically_revoked(): void
    {
        $admin = $this->makeAdmin();
        [$student, $course, $tx] = $this->makePaidTransaction();

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
            'progress_percentage' => 0,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), [])
            ->assertStatus(201);

        $this->assertSame('refunded', $tx->fresh()->status);
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
    }

    /* 17. existing refund webhook remains idempotent after an outbound refund */

    public function test_refund_webhook_remains_idempotent_after_outbound_refund(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction(25000);

        $refund = $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), [])
            ->assertStatus(201)
            ->json('refund');

        $this->assertSame('refunded', $tx->fresh()->status);

        $deliverWebhook = function (string $eventId) use ($tx, $refund) {
            $body = json_encode([
                'id' => $eventId,
                'event' => 'refund.processed',
                'payload' => ['refund' => ['entity' => [
                    'id' => $refund['provider_refund_id'],
                    'payment_id' => $tx->payment_id,
                    'amount' => 25000,
                ]]],
            ]);

            return $this->call('POST', '/api/payments/razorpay/webhook', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RAZORPAY_SIGNATURE' => 'stub-signature',
            ], $body);
        };

        // Same provider refund id delivered twice (distinct webhook event ids
        // so the event ledger itself does not dedupe first): no duplicate
        // refund row, transaction stays refunded.
        $deliverWebhook('evt_outbound_ref_1')->assertOk();
        $deliverWebhook('evt_outbound_ref_2')->assertOk();

        $this->assertSame(1, PaymentRefund::where('payment_transaction_id', $tx->id)->count());
        $this->assertSame('refunded', $tx->fresh()->status);
    }

    /* Unknown-outcome safety: gateway accepted, response lost, local rolled
     * back. The retry must reconcile the orphan instead of refunding twice.
     */

    public function test_retry_after_lost_response_does_not_double_refund(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction(100000);
        StubPaymentProvider::loseNextRefundResponse();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 30000, 'idempotency_key' => 'lost-1'])
            ->assertStatus(502)
            ->assertJson(['error' => 'provider_failed']);

        // Provider accepted exactly one refund; locally nothing was recorded.
        $this->assertSame(1, StubPaymentProvider::issuedCountFor($tx->payment_id));
        $this->assertSame(0, PaymentRefund::where('payment_transaction_id', $tx->id)->count());
        $this->assertSame('paid', $tx->fresh()->status);

        $orphanId = StubPaymentProvider::issuedRefundsFor($tx->payment_id)[0]['id'];

        // Same logical retry: reconciles the orphan, issues nothing new.
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 30000, 'idempotency_key' => 'lost-1'])
            ->assertStatus(201);

        $response->assertJsonPath('refund.provider_refund_id', $orphanId);
        $response->assertJsonPath('payment.remaining_refundable', 70000);

        $this->assertSame(1, StubPaymentProvider::issuedCountFor($tx->payment_id));
        $this->assertSame(1, PaymentRefund::where('payment_transaction_id', $tx->id)->count());
        $this->assertSame(
            30000,
            (int) PaymentRefund::where('payment_transaction_id', $tx->id)
                ->where('status', PaymentRefund::STATUS_SUCCEEDED)
                ->sum('amount_paise')
        );
    }

    public function test_reconciled_orphan_reduces_remaining_before_new_issue(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction(100000);
        StubPaymentProvider::loseNextRefundResponse();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 30000, 'idempotency_key' => 'lost-2'])
            ->assertStatus(502);

        // A NEW request for 80000 must see only 70000 remaining: the orphan
        // is reconciled before any new provider call, so no second refund
        // is issued at the gateway.
        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 80000, 'idempotency_key' => 'new-2'])
            ->assertStatus(422)
            ->assertJson(['error' => 'amount_exceeds_remaining']);

        $this->assertSame(1, StubPaymentProvider::issuedCountFor($tx->payment_id));
        $this->assertDatabaseHas('payment_refunds', [
            'payment_transaction_id' => $tx->id,
            'amount_paise' => 30000,
            'source' => PaymentRefund::SOURCE_RECONCILED,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_refund_reconciled']);
        $this->assertSame('partially_refunded', $tx->fresh()->status);
    }

    public function test_definite_failure_remains_retryable(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction(100000);
        StubPaymentProvider::failNextRefund();

        // Definite rejection: nothing moved provider-side, nothing recorded.
        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 30000, 'idempotency_key' => 'retry-1'])
            ->assertStatus(502)
            ->assertJson(['error' => 'provider_failed']);

        $this->assertSame(0, StubPaymentProvider::issuedCountFor($tx->payment_id));
        $this->assertSame(0, PaymentRefund::where('payment_transaction_id', $tx->id)->count());

        // Same logical retry proceeds to the provider and succeeds once.
        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 30000, 'idempotency_key' => 'retry-1'])
            ->assertStatus(201)
            ->assertJsonPath('payment.remaining_refundable', 70000);

        $this->assertSame(1, StubPaymentProvider::issuedCountFor($tx->payment_id));
        $this->assertSame(1, PaymentRefund::where('payment_transaction_id', $tx->id)->count());
    }

    public function test_idempotency_key_with_different_amount_conflicts(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 25000, 'idempotency_key' => 'same-key-diff-amount'])
            ->assertStatus(201);

        // Same key but a different explicit amount is a different logical
        // request: never silently return the wrong row.
        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 30000, 'idempotency_key' => 'same-key-diff-amount'])
            ->assertStatus(409)
            ->assertJson(['error' => 'idempotency_key_conflict']);

        $this->assertSame(1, PaymentRefund::where('payment_transaction_id', $tx->id)->count());
        $this->assertSame(
            25000,
            (int) PaymentRefund::where('payment_transaction_id', $tx->id)->sum('amount_paise')
        );
    }

    public function test_webhook_after_reconciled_refund_no_duplicate(): void
    {
        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction(25000);
        StubPaymentProvider::loseNextRefundResponse();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['idempotency_key' => 'lost-webhook-1'])
            ->assertStatus(502);

        $refund = $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['idempotency_key' => 'lost-webhook-1'])
            ->assertStatus(201)
            ->json('refund');

        $this->assertSame('refunded', $tx->fresh()->status);

        $deliverWebhook = function (string $eventId) use ($tx, $refund) {
            $body = json_encode([
                'id' => $eventId,
                'event' => 'refund.processed',
                'payload' => ['refund' => ['entity' => [
                    'id' => $refund['provider_refund_id'],
                    'payment_id' => $tx->payment_id,
                    'amount' => 25000,
                ]]],
            ]);

            return $this->call('POST', '/api/payments/razorpay/webhook', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RAZORPAY_SIGNATURE' => 'stub-signature',
            ], $body);
        };

        $deliverWebhook('evt_reconciled_ref_1')->assertOk();
        $deliverWebhook('evt_reconciled_ref_2')->assertOk();

        $this->assertSame(1, PaymentRefund::where('payment_transaction_id', $tx->id)->count());
        $this->assertSame('refunded', $tx->fresh()->status);
    }

    public function test_audit_and_ledger_contain_no_secrets(): void
    {
        config([
            'services.razorpay.key_id' => 'rzp_live_audit_sentinel_1',
            'services.razorpay.key_secret' => 'secret_audit_sentinel_2',
            'services.razorpay.webhook_secret' => 'whsec_audit_sentinel_3',
        ]);

        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 10000, 'reason' => 'Goodwill'])
            ->assertStatus(201);

        $blobs = [];

        foreach (AuditLog::all() as $log) {
            $blobs[] = json_encode($log->old_values).json_encode($log->new_values);
        }

        foreach (PaymentRefund::all() as $refund) {
            $blobs[] = json_encode($refund->metadata).$refund->provider_refund_id;
        }

        $haystack = implode("\n", $blobs);

        foreach (['rzp_live_audit_sentinel_1', 'secret_audit_sentinel_2', 'whsec_audit_sentinel_3'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $haystack);
        }

        $this->assertStringNotContainsStringIgnoringCase('authorization', $haystack);
    }

    /* 18. payment/provider credentials are never exposed in API errors */

    public function test_provider_credentials_never_exposed_in_api_errors(): void
    {
        config([
            'services.razorpay.key_id' => 'rzp_live_sentinel_abc123',
            'services.razorpay.key_secret' => 'secret_sentinel_xyz789',
            'services.razorpay.webhook_secret' => 'whsec_sentinel_456',
        ]);

        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction();
        StubPaymentProvider::failNextRefund();

        $responses = [
            $this->actingAs($admin, 'sanctum')->postJson($this->refundUrl($tx), ['amount' => 1000]),
            $this->actingAs($admin, 'sanctum')->postJson($this->refundUrl($tx), ['amount' => 999999999]),
            $this->actingAs($admin, 'sanctum')->postJson($this->refundUrl($tx), ['amount' => -5]),
            $this->actingAs($admin, 'sanctum')->postJson('/api/admin/payments/999999/refund', ['amount' => 100]),
        ];

        foreach ($responses as $response) {
            $body = $response->getContent() ?: '';
            $this->assertStringNotContainsString('rzp_live_sentinel_abc123', $body);
            $this->assertStringNotContainsString('secret_sentinel_xyz789', $body);
            $this->assertStringNotContainsString('whsec_sentinel_456', $body);
            $this->assertStringNotContainsStringIgnoringCase('authorization', $body);
            $this->assertStringNotContainsStringIgnoringCase('stack trace', $body);
        }
    }

    /* Razorpay wiring without network: missing credentials fail closed */

    public function test_razorpay_refund_without_credentials_raises_config_error(): void
    {
        config(['payment.default_provider' => 'razorpay']);
        config(['services.razorpay.key_id' => '']);
        config(['services.razorpay.key_secret' => '']);

        $admin = $this->makeAdmin();
        [, , $tx] = $this->makePaidTransaction();
        $tx->update(['provider' => 'razorpay']);

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->refundUrl($tx), ['amount' => 1000])
            ->assertStatus(503)
            ->assertJson(['error' => 'provider_not_configured']);

        $this->assertSame(0, PaymentRefund::count());
    }

    /* Uncaptured / failed payments are not refundable */

    public function test_uncaptured_and_failed_payments_rejected(): void
    {
        $admin = $this->makeAdmin();

        foreach (['created', 'stub_created', 'authorized', 'failed'] as $status) {
            [, , $tx] = $this->makePaidTransaction(100000, $status);

            $this->actingAs($admin, 'sanctum')
                ->postJson($this->refundUrl($tx), ['amount' => 1000])
                ->assertStatus(422)
                ->assertJson(['error' => 'not_refundable']);
        }

        $this->assertSame(0, PaymentRefund::count());
    }
}
