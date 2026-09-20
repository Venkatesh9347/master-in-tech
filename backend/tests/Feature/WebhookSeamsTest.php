<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\PaymentTransaction;
use App\Models\Section;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\Payment\PaymentService;
use App\Services\Payment\Providers\StubPaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9 business seams: each of payment.paid, payment.refunded,
 * enrollment.created, and certificate.issued must produce exactly one
 * webhook delivery after the successful business operation — and nothing
 * when the operation fails or rolls back.
 *
 * Portable across SQLite/MySQL/PostgreSQL: no PRAGMA, no engine-specific
 * error text, no hardcoded driver names.
 */
class WebhookSeamsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['payment.default_provider' => 'stub']);
        StubPaymentProvider::resetFailure();
    }

    private function makeCourse(string $prefix = 'Course'): Course
    {
        $title = $prefix . ' ' . Str::random(6);

        return Course::create([
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(4),
            'description' => 'Webhook seam fixture course.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => 1000.00,
        ]);
    }

    private function subscribe(string $event): WebhookSubscription
    {
        return WebhookSubscription::create([
            'target_url' => 'https://hooks.example.com/deliveries',
            'secret' => str_repeat('s', 32),
            'events' => [$event],
            'is_active' => true,
        ]);
    }

    private function makeTransaction(string $status = 'created'): PaymentTransaction
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->makeCourse();

        return PaymentTransaction::create([
            'provider' => 'stub',
            'order_id' => 'order_hook_' . Str::random(8),
            'payment_id' => 'pay_hook_' . Str::random(8),
            'idempotency_key' => 'hook-key-' . Str::random(8),
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount_paise' => 100000,
            'currency' => 'INR',
            'status' => $status,
        ]);
    }

    private function deliveries(string $event): int
    {
        return WebhookDelivery::where('event', $event)->count();
    }

    public function test_payment_paid_emits_exactly_one_delivery(): void
    {
        $this->subscribe('payment.paid');
        $tx = $this->makeTransaction();

        $service = app(PaymentService::class);
        $service->applyPaymentEvent($tx->payment_id, 'captured', []);

        $this->assertSame('paid', $tx->fresh()->status);
        $this->assertSame(1, $this->deliveries('payment.paid'));

        $delivery = WebhookDelivery::where('event', 'payment.paid')->first();
        $this->assertSame($tx->payment_id, $delivery->payload['payment_id']);

        // Idempotent replay of the same event emits nothing further.
        $service->applyPaymentEvent($tx->payment_id, 'captured', []);
        $this->assertSame(1, $this->deliveries('payment.paid'));
    }

    public function test_payment_refunded_emits_exactly_one_delivery(): void
    {
        $this->subscribe('payment.refunded');
        $tx = $this->makeTransaction();

        $service = app(PaymentService::class);
        $service->applyPaymentEvent($tx->payment_id, 'captured', []);
        $service->applyPaymentEvent($tx->payment_id, 'refunded', []);

        $this->assertSame('refunded', $tx->fresh()->status);
        $this->assertSame(1, $this->deliveries('payment.refunded'));
    }

    public function test_admin_refund_emits_refunded_delivery(): void
    {
        $this->subscribe('payment.refunded');
        $admin = User::factory()->create(['role' => 'admin']);
        $tx = $this->makeTransaction('paid');

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/payments/{$tx->id}/refund", []);

        $response->assertStatus(201);
        $this->assertSame('refunded', $tx->fresh()->status);
        $this->assertSame(1, $this->deliveries('payment.refunded'));
    }

    public function test_enrollment_created_emits_exactly_one_delivery(): void
    {
        $this->subscribe('enrollment.created');
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->makeCourse('Enroll');

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/enrollments', [
                'user_id' => $student->id,
                'course_id' => $course->id,
                'status' => 'pending',
            ]);

        $response->assertStatus(201);
        $this->assertSame(1, $this->deliveries('enrollment.created'));

        $delivery = WebhookDelivery::where('event', 'enrollment.created')->first();
        $this->assertSame($student->id, (int) $delivery->payload['user_id']);
        $this->assertSame($course->id, (int) $delivery->payload['course_id']);
    }

    public function test_certificate_issued_emits_exactly_one_delivery(): void
    {
        $this->subscribe('certificate.issued');
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->makeCourse('Cert');

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'progress_percentage' => 100,
        ]);

        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Core Lesson',
            'type' => 'video',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        LessonProgress::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'section_id' => $section->id,
            'completed' => true,
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/certificate");

        $response->assertCreated();
        $this->assertSame(1, $this->deliveries('certificate.issued'));

        $delivery = WebhookDelivery::where('event', 'certificate.issued')->first();
        $this->assertSame(
            Certificate::where('user_id', $student->id)->first()->id,
            (int) $delivery->payload['certificate_id']
        );
    }

    public function test_failed_business_transaction_emits_nothing(): void
    {
        $this->subscribe('enrollment.created');
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->makeCourse('Rollback');

        try {
            DB::transaction(function () use ($student, $course): void {
                CourseEnrollment::create([
                    'user_id' => $student->id,
                    'course_id' => $course->id,
                    'status' => 'active',
                ]);

                throw new \RuntimeException('Simulated business failure.');
            });

            $this->fail('The simulated failure should have rolled back.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated business failure.', $e->getMessage());
        }

        $this->assertSame(0, CourseEnrollment::count());
        $this->assertSame(0, $this->deliveries('enrollment.created'));
    }

    public function test_rejected_certificate_request_emits_nothing(): void
    {
        $this->subscribe('certificate.issued');
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->makeCourse('Empty');

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'progress_percentage' => 100,
        ]);

        // No lessons exist, so issuance is rejected with 422 inside the
        // business transaction — no webhook may be produced.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/certificate")
            ->assertStatus(422);

        $this->assertSame(0, $this->deliveries('certificate.issued'));
    }
}
