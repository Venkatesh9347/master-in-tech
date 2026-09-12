<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Enquiry;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B3 pay-before-classroom + payment integrity regression suite.
 *
 * Locked product contract: active LMS access requires a verified
 * PaymentTransaction (exact user + course, status=paid) or an explicit
 * admin/super_admin override with reason + audit.
 */
class B3PaymentEnrollmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookSecret = 'whsec_b3_test_secret';

    private function createCourse(array $overrides = []): Course
    {
        $title = $overrides['title'] ?? 'B3 Course '.Str::random(5);

        return Course::create(array_merge([
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(4),
            'description' => 'B3 regression course.',
            'instructor' => 'Faculty',
            'duration' => '8 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => 5000,
        ], $overrides));
    }

    private function createLead(array $overrides = []): Enquiry
    {
        return Enquiry::create(array_merge([
            'name' => 'B3 Lead',
            'email' => 'b3lead-'.strtolower(Str::random(6)).'@example.com',
            'phone' => '9000000001',
            'status' => Enquiry::STATUS_NEW,
        ], $overrides));
    }

    private function paidTransaction(User $user, Course $course, array $overrides = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'provider' => config('payment.default_provider', 'stub'),
            'order_id' => 'order_b3_'.Str::random(8),
            'payment_id' => 'pay_b3_'.Str::random(8),
            'idempotency_key' => 'b3key_'.Str::random(10),
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount_paise' => (int) round((float) $course->price * 100),
            'currency' => 'INR',
            'status' => 'paid',
            'paid_at' => now(),
        ], $overrides));
    }

    private function signWebhook(string $raw): string
    {
        return hash_hmac('sha256', $raw, $this->webhookSecret);
    }

    private function postWebhookRaw(string $raw, string $signature)
    {
        return $this->call('POST', '/api/payments/razorpay/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
        ], $raw);
    }

    // 1. Scoped payment_event is rejected AND leaves no activity row.
    public function test_scoped_payment_event_leaves_no_activity_row(): void
    {
        $staff = User::factory()->create(['role' => 'telecaller']);
        $lead = $this->createLead(['assigned_counsellor_id' => $staff->id]);
        Sanctum::actingAs($staff);

        $this->postJson("/api/admin/crm/leads/{$lead->id}/activities", [
            'activity_type' => 'payment_event',
            'title' => 'Fake UPI',
            'metadata' => ['amount' => 9999],
        ])->assertForbidden();

        $this->assertDatabaseMissing('crm_activities', [
            'enquiry_id' => $lead->id,
            'title' => 'Fake UPI',
        ]);
        $this->assertSame(0.0, (float) $lead->fresh()->amount_paid);
    }

    // 2. Scoped note/call carrying financial metadata is rejected/stripped.
    public function test_scoped_note_with_financial_metadata_is_rejected(): void
    {
        $staff = User::factory()->create(['role' => 'course_advisor']);
        $lead = $this->createLead(['assigned_counsellor_id' => $staff->id]);
        Sanctum::actingAs($staff);

        foreach (['note', 'call', 'follow_up'] as $type) {
            $this->postJson("/api/admin/crm/leads/{$lead->id}/activities", [
                'activity_type' => $type,
                'title' => 'Smuggled amount',
                'metadata' => ['transaction_id' => 'TXN-SMUGGLER', 'amount' => 12345],
            ])->assertForbidden();
        }

        $this->assertDatabaseMissing('crm_activities', ['enquiry_id' => $lead->id, 'title' => 'Smuggled amount']);
        $this->assertSame(0.0, (float) $lead->fresh()->amount_paid);
        $this->assertSame('unpaid', $lead->fresh()->payment_status);
    }

    // 3. Admin invalid amounts are rejected.
    public function test_admin_invalid_payment_amounts_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $lead = $this->createLead();
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/crm/leads/{$lead->id}/activities", [
            'activity_type' => 'payment_event',
            'title' => 'Negative',
            'metadata' => ['amount' => -500],
        ])->assertStatus(422);

        $this->postJson("/api/admin/crm/leads/{$lead->id}/activities", [
            'activity_type' => 'payment_event',
            'title' => 'Huge',
            'metadata' => ['amount' => 999999999],
        ])->assertStatus(422);

        $this->postJson("/api/admin/crm/leads/{$lead->id}/activities", [
            'activity_type' => 'payment_event',
            'title' => 'Malformed',
            'metadata' => ['amount' => 'lots-of-money'],
        ])->assertStatus(422);

        $this->assertSame(0.0, (float) $lead->fresh()->amount_paid);
    }

    // 4. Lowering amount_paid requires an explicit correction reason + audit.
    public function test_ledger_lowering_requires_correction_reason(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $lead = $this->createLead(['amount_paid' => 8000, 'payment_status' => 'partial']);
        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/crm/leads/{$lead->id}", [
            'amount_paid' => 1000,
        ])->assertStatus(422);
        $this->assertSame(8000.0, (float) $lead->fresh()->amount_paid);

        $this->putJson("/api/admin/crm/leads/{$lead->id}", [
            'amount_paid' => 1000,
            'correction_reason' => 'Duplicate entry recorded twice by finance desk.',
        ])->assertOk();
        $this->assertSame(1000.0, (float) $lead->fresh()->amount_paid);
        $this->assertDatabaseHas('audit_logs', ['action' => 'recorded_lead_payment']);

        // Paid/partial status without money is rejected.
        $lead2 = $this->createLead();
        $this->putJson("/api/admin/crm/leads/{$lead2->id}", [
            'payment_status' => 'paid',
        ])->assertStatus(422);
    }

    // 5. Unpaid CRM conversion stays pending with LMS blocked.
    public function test_unpaid_crm_conversion_has_no_lms_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->createCourse();
        $lead = $this->createLead(['status' => Enquiry::STATUS_ADMISSION_CONFIRMED]);
        Sanctum::actingAs($admin);

        $res = $this->postJson("/api/admin/crm/leads/{$lead->id}/convert", [
            'course_id' => $course->id,
        ]);
        $res->assertOk()->assertJsonFragment(['payment_required' => true]);

        $user = User::where('email', $lead->email)->first();
        $this->assertNotNull($user);
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $user->id, 'course_id' => $course->id, 'status' => 'pending',
        ]);

        Sanctum::actingAs($user);
        $this->getJson("/api/courses/{$course->id}/lms-progress")->assertForbidden();
        $this->getJson("/api/courses/{$course->id}/progress")->assertForbidden();
    }

    // 6. Unpaid admin enrollment stays pending with LMS blocked.
    public function test_unpaid_admin_enrollment_has_no_lms_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/enrollments', [
            'user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active',
        ])->assertCreated()->assertJsonFragment(['payment_required' => true]);

        Sanctum::actingAs($student);
        $this->getJson("/api/courses/{$course->id}/lms-progress")->assertForbidden();
    }

    // 7. Unpaid batch enrollment defers cohort placement.
    public function test_unpaid_batch_enrollment_defers_placement(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();
        $batch = Batch::create([
            'name' => 'B3 Cohort', 'code' => 'B3COHORT01', 'course_id' => $course->id,
            'start_date' => now()->addDays(3)->toDateString(), 'status' => 'upcoming',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/batches/{$batch->id}/students", [
            'user_id' => $student->id,
        ])->assertCreated()->assertJsonFragment(['payment_required' => true]);

        $this->assertDatabaseMissing('batch_students', [
            'batch_id' => $batch->id, 'user_id' => $student->id, 'status' => 'active',
        ]);
    }

    // 8. Verified paid enrollment grants active LMS access.
    public function test_verified_paid_conversion_grants_lms_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->createCourse();
        $email = 'paid-'.strtolower(Str::random(6)).'@example.com';
        $student = User::factory()->create(['role' => 'student', 'email' => $email]);
        $this->paidTransaction($student, $course);
        $lead = $this->createLead(['email' => $email, 'status' => Enquiry::STATUS_ADMISSION_CONFIRMED]);
        Sanctum::actingAs($admin);

        $res = $this->postJson("/api/admin/crm/leads/{$lead->id}/convert", [
            'course_id' => $course->id,
        ]);
        $res->assertOk();
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active',
        ]);

        Sanctum::actingAs($student);
        $this->getJson("/api/courses/{$course->id}/lms-progress")->assertOk();
    }

    // 8b. Admin override grants access with reason + audit; scoped override is forbidden.
    public function test_admin_override_requires_reason_and_audit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'telecaller']);
        $course = $this->createCourse();

        $lead = $this->createLead(['status' => Enquiry::STATUS_ADMISSION_CONFIRMED]);
        Sanctum::actingAs($staff);
        $this->postJson("/api/admin/crm/leads/{$lead->id}/convert", [
            'course_id' => $course->id, 'override_reason' => 'Trying to override as staff.',
        ])->assertForbidden();

        Sanctum::actingAs($admin);
        $res = $this->postJson("/api/admin/crm/leads/{$lead->id}/convert", [
            'course_id' => $course->id, 'override_reason' => 'Registrar-approved scholarship admission.',
        ]);
        $res->assertOk()->assertJsonFragment(['via_override' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'enrollment_payment_override']);
    }

    // 9. Batch/course mismatch fails safely.
    public function test_mismatched_batch_course_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $courseA = $this->createCourse();
        $courseB = $this->createCourse();
        $batchB = Batch::create([
            'name' => 'Other Cohort', 'code' => 'B3OTHER01', 'course_id' => $courseB->id,
            'start_date' => now()->addDays(3)->toDateString(), 'status' => 'upcoming',
        ]);
        $lead = $this->createLead(['status' => Enquiry::STATUS_ADMISSION_CONFIRMED]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/crm/leads/{$lead->id}/convert", [
            'course_id' => $courseA->id, 'batch_id' => $batchB->id,
        ])->assertStatus(422);
    }

    // 10+11. Legacy reassignment + admission-state bypasses are forbidden.
    public function test_legacy_reassignment_and_admission_bypass_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'telecaller', 'name' => 'Tina Caller']);
        $other = User::factory()->create(['role' => 'telecaller', 'name' => 'Oscar Other']);
        $lead = $this->createLead(['assigned_counsellor_id' => $staff->id]);
        Sanctum::actingAs($staff);

        $this->putJson("/api/admin/enquiries/{$lead->id}", [
            'assigned_agent' => 'Oscar Other',
        ])->assertForbidden();

        $this->putJson("/api/admin/enquiries/{$lead->id}", [
            'status' => Enquiry::STATUS_ADMISSION_CONFIRMED,
        ])->assertForbidden();

        // Claiming for self still works.
        $this->putJson("/api/admin/enquiries/{$lead->id}", [
            'status' => 'contacted',
        ])->assertOk();
    }

    // 12. Duplicate webhook has one effect.
    public function test_duplicate_webhook_has_single_effect(): void
    {
        config(['payment.default_provider' => 'razorpay', 'services.razorpay.webhook_secret' => $this->webhookSecret]);
        $course = $this->createCourse(['price' => 100]);
        $student = User::factory()->create(['role' => 'student']);
        $tx = PaymentTransaction::create([
            'provider' => 'razorpay', 'order_id' => 'order_dup_1', 'payment_id' => '',
            'idempotency_key' => 'dup-key-1', 'user_id' => $student->id, 'course_id' => $course->id,
            'amount_paise' => 10000, 'currency' => 'INR', 'status' => 'created',
        ]);

        $raw = json_encode([
            'id' => 'evt_dup_1', 'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_dup_1', 'order_id' => 'order_dup_1', 'amount' => 10000, 'currency' => 'INR', 'status' => 'captured',
            ]]],
        ]);

        $this->postWebhookRaw($raw, $this->signWebhook($raw))->assertOk();
        $this->postWebhookRaw($raw, $this->signWebhook($raw))->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertEquals(1, PaymentWebhookEvent::where('provider', 'razorpay')->where('provider_event_id', 'evt_dup_1')->count());
        $this->assertEquals(1, CourseEnrollment::where('user_id', $student->id)->where('course_id', $course->id)->count());
        $this->assertEquals('paid', $tx->fresh()->status);
    }

    // 13. Crash recovery: received -> retry -> processed/paid.
    public function test_webhook_crash_recovery_reprocesses_received(): void
    {
        config(['payment.default_provider' => 'razorpay', 'services.razorpay.webhook_secret' => $this->webhookSecret]);
        $course = $this->createCourse(['price' => 100]);
        $student = User::factory()->create(['role' => 'student']);
        $tx = PaymentTransaction::create([
            'provider' => 'razorpay', 'order_id' => 'order_crash_1', 'payment_id' => '',
            'idempotency_key' => 'crash-key-1', 'user_id' => $student->id, 'course_id' => $course->id,
            'amount_paise' => 10000, 'currency' => 'INR', 'status' => 'created',
        ]);

        // Simulate a crash: ledger stuck at received, payment never applied.
        PaymentWebhookEvent::create([
            'provider' => 'razorpay', 'provider_event_id' => 'evt_crash_1',
            'event_type' => 'payment.captured', 'payload' => ['crashed' => true],
            'status' => PaymentWebhookEvent::STATUS_RECEIVED,
        ]);

        $raw = json_encode([
            'id' => 'evt_crash_1', 'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_crash_1', 'order_id' => 'order_crash_1', 'amount' => 10000, 'currency' => 'INR', 'status' => 'captured',
            ]]],
        ]);

        $res = $this->postWebhookRaw($raw, $this->signWebhook($raw));
        $res->assertOk();
        $this->assertNotEquals('duplicate', $res->json('status'));
        $this->assertEquals('paid', $tx->fresh()->status);
        $this->assertEquals(PaymentWebhookEvent::STATUS_PROCESSED, PaymentWebhookEvent::where('provider_event_id', 'evt_crash_1')->first()->status);
    }

    // 14. Null event IDs dedupe via fallback key.
    public function test_null_event_id_dedupes_identical_deliveries(): void
    {
        config(['payment.default_provider' => 'razorpay', 'services.razorpay.webhook_secret' => $this->webhookSecret]);
        $course = $this->createCourse(['price' => 100]);
        $student = User::factory()->create(['role' => 'student']);
        PaymentTransaction::create([
            'provider' => 'razorpay', 'order_id' => 'order_noid_1', 'payment_id' => '',
            'idempotency_key' => 'noid-key-1', 'user_id' => $student->id, 'course_id' => $course->id,
            'amount_paise' => 10000, 'currency' => 'INR', 'status' => 'created',
        ]);

        $raw = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_noid_1', 'order_id' => 'order_noid_1', 'amount' => 10000, 'currency' => 'INR', 'status' => 'captured',
            ]]],
        ]);

        $this->postWebhookRaw($raw, $this->signWebhook($raw))->assertOk();
        $second = $this->postWebhookRaw($raw, $this->signWebhook($raw))->assertOk();
        $this->assertEquals('duplicate', $second->json('status'));
        $this->assertEquals(1, CourseEnrollment::where('user_id', $student->id)->where('course_id', $course->id)->count());
    }

    // 15. Concurrent order creation resolves to one winner.
    public function test_concurrent_order_creation_resolves_to_winner(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse(['price' => 250]);
        Sanctum::actingAs($student);

        $first = $this->postJson('/api/payments/order', ['course_id' => $course->id])->assertCreated();
        $second = $this->postJson('/api/payments/order', ['course_id' => $course->id])->assertCreated();

        $this->assertEquals($first->json('order.order_id'), $second->json('order.order_id'));
        $this->assertEquals(1, PaymentTransaction::where('user_id', $student->id)->where('course_id', $course->id)->count());
    }

    // 16. Stale price never reuses an old-price order.
    public function test_stale_price_creates_versioned_order(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse(['price' => 100]);
        Sanctum::actingAs($student);

        $first = $this->postJson('/api/payments/order', ['course_id' => $course->id])->assertCreated();
        $this->assertEquals(10000, $first->json('order.amount'));

        $course->update(['price' => 200]);
        $second = $this->postJson('/api/payments/order', ['course_id' => $course->id])->assertCreated();

        $this->assertEquals(20000, $second->json('order.amount'));
        $this->assertNotEquals($first->json('order.order_id'), $second->json('order.order_id'));
        $this->assertEquals(2, PaymentTransaction::where('user_id', $student->id)->where('course_id', $course->id)->count());
    }

    // 17. Repurchase after refund creates a new order; old stays refunded.
    public function test_repurchase_after_refund_creates_new_order(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse(['price' => 150]);
        Sanctum::actingAs($student);

        $first = $this->postJson('/api/payments/order', ['course_id' => $course->id])->assertCreated();
        $tx = PaymentTransaction::where('user_id', $student->id)->where('course_id', $course->id)->first();
        $tx->update(['status' => 'paid', 'payment_id' => 'pay_ref_1', 'paid_at' => now()]);

        app(PaymentService::class)->recordRefund('pay_ref_1', ['provider' => config('payment.default_provider', 'stub')]);
        $this->assertEquals('refunded', $tx->fresh()->status);

        $second = $this->postJson('/api/payments/order', ['course_id' => $course->id])->assertCreated();
        $this->assertNotEquals($first->json('order.order_id'), $second->json('order.order_id'));
        $this->assertEquals('refunded', $tx->fresh()->status);
    }

    // 18. Invalid terminal transitions are prevented.
    public function test_invalid_terminal_transitions_prevented(): void
    {
        $service = app(PaymentService::class);
        $tx = PaymentTransaction::create([
            'provider' => 'stub', 'order_id' => 'order_term_1', 'payment_id' => 'pay_term_1',
            'idempotency_key' => 'term-key-1', 'amount_paise' => 5000, 'currency' => 'INR', 'status' => 'paid',
        ]);

        $service->applyPaymentEvent('pay_term_1', 'failed', ['provider' => 'stub']);
        $this->assertEquals('paid', $tx->fresh()->status);

        $service->applyPaymentEvent('pay_term_1', 'refunded', ['provider' => 'stub']);
        $this->assertEquals('refunded', $tx->fresh()->status);

        $service->applyPaymentEvent('pay_term_1', 'paid', ['provider' => 'stub']);
        $this->assertEquals('refunded', $tx->fresh()->status);
    }

    // 19. super_admin parity across admin surfaces.
    public function test_super_admin_parity(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $tutor = User::factory()->create(['role' => 'tutor']);
        $course = $this->createCourse();
        $course->update(['instructor_id' => $tutor->id]);
        Sanctum::actingAs($super);

        $this->getJson("/api/admin/courses/{$course->id}/students")->assertOk();
        $this->postJson('/api/tutor/courses', [
            'title' => 'Super Admin Course', 'description' => 'Created by super admin.',
            'duration' => '4 weeks', 'difficulty' => 'Beginner',
        ])->assertCreated();
        $this->postJson("/api/courses/{$course->id}/sections", ['title' => 'Super Section'])->assertCreated();
    }

    // 20. super_admin grant remains protected.
    public function test_super_admin_grant_remains_protected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $victim = User::factory()->create(['role' => 'student']);

        $this->postJson('/api/admin/users', [
            'name' => 'Sneaky', 'email' => 'sneaky-'.Str::random(5).'@example.com', 'role' => 'super_admin',
        ])->assertForbidden();
        $this->putJson("/api/admin/users/{$victim->id}/role", ['role' => 'super_admin'])->assertForbidden();
    }

    // 21. Production payment guard fails closed only in production.
    public function test_production_payment_guard(): void
    {
        $service = app(PaymentService::class);

        // Non-production with stub is fine.
        $service->ensureProductionPaymentConfigured();
        $this->assertTrue(true);

        // Production with stub must fail closed (no secrets in message).
        $appEnv = app()->environment();
        try {
            app()->detectEnvironment(fn () => 'production');
            config(['payment.default_provider' => 'stub']);
            try {
                $service->ensureProductionPaymentConfigured();
                $this->fail('Production with stub provider must throw.');
            } catch (\App\Services\Payment\Exceptions\PaymentNotConfiguredException $e) {
                $this->assertStringNotContainsString('sk_', $e->getMessage());
                $this->assertStringNotContainsString('secret', strtolower($e->getMessage()));
            }

            // Production with razorpay but missing secrets must fail closed.
            config([
                'payment.default_provider' => 'razorpay',
                'services.razorpay.key_id' => '',
                'services.razorpay.key_secret' => '',
                'services.razorpay.webhook_secret' => '',
            ]);
            try {
                $service->ensureProductionPaymentConfigured();
                $this->fail('Production with missing secrets must throw.');
            } catch (\App\Services\Payment\Exceptions\PaymentNotConfiguredException $e) {
                $this->assertTrue(true);
            }
        } finally {
            app()->detectEnvironment(fn () => $appEnv);
        }
    }

    // 22. Unknown/ignored webhooks never enroll and keep a safe ledger state.
    public function test_unknown_webhook_never_enrolls(): void
    {
        config(['payment.default_provider' => 'razorpay', 'services.razorpay.webhook_secret' => $this->webhookSecret]);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();

        $raw = json_encode(['id' => 'evt_unknown_1', 'event' => 'invoice.generated', 'payload' => []]);
        $this->postWebhookRaw($raw, $this->signWebhook($raw))->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertEquals(0, CourseEnrollment::where('user_id', $student->id)->where('course_id', $course->id)->count());
    }

    // Follow-up ownership: scoped staff cannot touch others' follow-ups; delete is admin-only.
    public function test_follow_up_ownership_and_delete_restrictions(): void
    {
        $a = User::factory()->create(['role' => 'telecaller']);
        $b = User::factory()->create(['role' => 'telecaller']);
        $leadA = $this->createLead(['assigned_counsellor_id' => $a->id]);

        Sanctum::actingAs($a);
        $created = $this->postJson("/api/admin/crm/leads/{$leadA->id}/follow-ups", [
            'scheduled_at' => now()->addDay()->toISOString(), 'title' => 'Call back',
        ])->assertCreated();
        $followUpId = $created->json('follow_up.id');

        // Cross-assign is forbidden.
        $this->postJson("/api/admin/crm/leads/{$leadA->id}/follow-ups", [
            'scheduled_at' => now()->addDay()->toISOString(), 'title' => 'Hijack', 'assigned_to' => $b->id,
        ])->assertForbidden();

        Sanctum::actingAs($b);
        $this->putJson("/api/admin/crm/follow-ups/{$followUpId}", ['status' => 'completed'])->assertForbidden();

        // Lead deletion is admin-only.
        $this->deleteJson("/api/admin/crm/leads/{$leadA->id}")->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson("/api/admin/crm/follow-ups/{$followUpId}", ['status' => 'completed'])->assertOk();
    }
}
