<?php

namespace Tests\Feature;

use App\Jobs\SendTemplatedMailJob;
use App\Mail\TemplatedNotificationMail;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Enquiry;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\PaymentTransaction;
use App\Models\Section;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F1 general notifications: queued, allowlisted, stale-safe delivery for
 * enrollment / payment / batch / certificate / live-class events.
 */
class GeneralNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function createCourse(array $attributes = []): Course
    {
        $title = $attributes['title'] ?? 'Course ' . Str::random(5);

        return Course::create(array_merge([
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => 'Notification test course.',
            'category' => 'Engineering',
            'instructor' => 'Notify Instructor',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'price' => 100,
            'is_published' => true,
        ], $attributes));
    }

    private function createEnrollment(User $student, Course $course, string $status = 'active'): CourseEnrollment
    {
        return CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'enrolled_at' => now(),
            'status' => $status,
            'progress_percentage' => 0,
        ]);
    }

    private function runJob(string $event, int $userId, array $ref): void
    {
        (new SendTemplatedMailJob($event, $userId, $ref))->handle();
    }

    private function createLead(Course $course, array $attributes = []): Enquiry
    {
        return Enquiry::create(array_merge([
            'name' => 'Notify Lead',
            'email' => 'notify.lead.' . Str::random(6) . '@example.com',
            'phone' => '+91 9000000001',
            'course_id' => $course->id,
            'status' => Enquiry::STATUS_NEW,
        ], $attributes));
    }

    private function createBatch(Course $course, array $attributes = []): Batch
    {
        return Batch::create(array_merge([
            'name' => 'Notify Cohort',
            'code' => 'RITNOT' . strtoupper(Str::random(4)),
            'course_id' => $course->id,
            'start_date' => now()->addWeek()->toDateString(),
            'status' => 'upcoming',
            'max_students' => 30,
        ], $attributes));
    }

    private function assertSingleEnrollmentJob(User $student, ?string $expectedStatus = null): void
    {
        Queue::assertPushed(SendTemplatedMailJob::class, 1);
        Queue::assertPushed(SendTemplatedMailJob::class, function ($job) use ($student, $expectedStatus) {
            if ($job->event !== NotificationService::EVENT_ENROLLMENT_CREATED) {
                return false;
            }
            if ($job->userId !== $student->id) {
                return false;
            }
            if ($expectedStatus === null) {
                return true;
            }
            $enrollment = CourseEnrollment::find($job->ref['enrollment_id'] ?? null);

            return $enrollment && $enrollment->status === $expectedStatus;
        });
    }

    // -----------------------------------------------------------------
    // A. Service / infrastructure
    // -----------------------------------------------------------------

    public function test_enrollment_dispatch_is_queued_with_stable_payload(): void
    {
        Queue::fake();

        $student = User::factory()->create(['role' => 'student']);
        $enrollment = $this->createEnrollment($student, $this->createCourse());

        NotificationService::enrollmentCreated($enrollment);

        Queue::assertPushed(SendTemplatedMailJob::class, function ($job) use ($student, $enrollment) {
            return $job->event === NotificationService::EVENT_ENROLLMENT_CREATED
                && $job->userId === $student->id
                && ($job->ref['enrollment_id'] ?? null) === $enrollment->id;
        });
        Queue::assertPushed(SendTemplatedMailJob::class, 1);
    }

    public function test_unsupported_event_is_discarded_without_sending(): void
    {
        Mail::fake();

        $student = User::factory()->create(['role' => 'student']);

        $this->runJob('billing.refund_executed', $student->id, []);
        $this->runJob('', $student->id, []);

        Mail::assertNothingSent();
    }

    public function test_stale_or_missing_records_are_discarded(): void
    {
        Mail::fake();

        $student = User::factory()->create(['role' => 'student']);
        $enrollment = $this->createEnrollment($student, $this->createCourse());
        $enrollmentId = $enrollment->id;
        $enrollment->delete();

        // Deleted enrollment: nothing sent.
        $this->runJob(
            NotificationService::EVENT_ENROLLMENT_CREATED,
            $student->id,
            ['enrollment_id' => $enrollmentId]
        );

        // Missing user: nothing sent.
        $this->runJob(
            NotificationService::EVENT_ENROLLMENT_CREATED,
            999999,
            ['enrollment_id' => $enrollmentId]
        );

        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // B. Enrollment (all three creation paths, exactly once each)
    // -----------------------------------------------------------------

    public function test_admin_enrollment_store_notifies_student_once(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
        ])->assertStatus(201);

        $this->assertSingleEnrollmentJob($student);
    }

    public function test_crm_convert_notifies_student_once(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->createCourse();
        $lead = $this->createLead($course);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/crm/leads/{$lead->id}/convert", [
            'course_id' => $course->id,
        ])->assertStatus(200);

        $student = User::whereRaw('LOWER(email) = ?', [strtolower($lead->email)])->firstOrFail();
        $this->assertSingleEnrollmentJob($student, 'pending');
    }

    public function test_enquiry_enroll_notifies_student_once(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->createCourse();
        $enquiry = $this->createLead($course);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/enquiries/{$enquiry->id}/enroll", [
            'course_id' => $course->id,
        ])->assertStatus(200);

        $student = User::whereRaw('LOWER(email) = ?', [strtolower($enquiry->email)])->firstOrFail();
        $this->assertSingleEnrollmentJob($student, 'pending');
    }

    public function test_failed_enrollment_request_sends_nothing(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();
        $this->createEnrollment($student, $course);

        // Duplicate enrollment is rejected before the transaction body.
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // C. Payment (confirmed + failed, exactly-once, no secrets)
    // -----------------------------------------------------------------

    public function test_payment_confirm_notifies_student_once(): void
    {
        Queue::fake();
        config(['payment.default_provider' => 'stub']);

        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse(['price' => 250.00]);

        $order = $this->actingAs($student, 'sanctum')
            ->postJson('/api/payments/order', ['course_id' => $course->id])
            ->assertStatus(201)
            ->json('order');

        $this->actingAs($student, 'sanctum')->postJson('/api/payments/confirm', [
            'order_id' => $order['order_id'],
            'payment_id' => $order['payment_id'],
        ])->assertStatus(200);

        Queue::assertPushed(SendTemplatedMailJob::class, 1);
        Queue::assertPushed(SendTemplatedMailJob::class, function ($job) use ($student) {
            return $job->event === NotificationService::EVENT_PAYMENT_CONFIRMED
                && $job->userId === $student->id;
        });

        // Repeat confirm of the already-paid order stays silent.
        $this->actingAs($student, 'sanctum')->postJson('/api/payments/confirm', [
            'order_id' => $order['order_id'],
            'payment_id' => $order['payment_id'],
        ])->assertStatus(200);

        Queue::assertPushed(SendTemplatedMailJob::class, 1);
    }

    public function test_webhook_failed_notifies_once_and_duplicate_stays_silent(): void
    {
        Queue::fake();
        config([
            'payment.default_provider' => 'razorpay',
            'services.razorpay.webhook_secret' => 'whsec_notify_test',
        ]);

        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();
        PaymentTransaction::create([
            'provider' => 'razorpay',
            'order_id' => 'order_notify_1',
            'payment_id' => 'pay_notify_1',
            'idempotency_key' => 'notify-key-1',
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount_paise' => 10000,
            'currency' => 'INR',
            'status' => 'created',
        ]);

        $rawPayload = json_encode([
            'event' => 'payment.failed',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_notify_1']]],
        ]);
        $signature = hash_hmac('sha256', $rawPayload, 'whsec_notify_test');
        $send = fn () => $this->call('POST', '/api/payments/razorpay/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
        ], $rawPayload);

        $send()->assertStatus(200)->assertJson(['status' => 'failed']);

        Queue::assertPushed(SendTemplatedMailJob::class, 1);
        Queue::assertPushed(SendTemplatedMailJob::class, function ($job) use ($student) {
            return $job->event === NotificationService::EVENT_PAYMENT_FAILED
                && $job->userId === $student->id;
        });

        // Duplicate delivery is acknowledged without re-notifying.
        $send()->assertStatus(200);
        Queue::assertPushed(SendTemplatedMailJob::class, 1);
    }

    // -----------------------------------------------------------------
    // D. Batch (assigned / transferred / discontinued)
    // -----------------------------------------------------------------

    private function createBatchWithTutor(Course $course, User $tutor, array $attributes = []): Batch
    {
        return Batch::create(array_merge([
            'name' => 'Notify Cohort ' . Str::random(4),
            'code' => 'RITN' . strtoupper(Str::random(4)),
            'course_id' => $course->id,
            'tutor_id' => $tutor->id,
            'start_date' => now()->addWeek()->toDateString(),
            'status' => 'upcoming',
            'max_students' => 30,
        ], $attributes));
    }

    public function test_batch_assigned_notifies_student_and_tutor(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $tutor = User::factory()->create(['role' => 'tutor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();
        $batch = $this->createBatchWithTutor($course, $tutor);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/batches/{$batch->id}/students", [
            'user_id' => $student->id,
            'override_reason' => 'Verified offline payment receipt RCPT-1.',
        ])->assertStatus(201);

        Queue::assertPushed(SendTemplatedMailJob::class, 2);
        Queue::assertPushed(SendTemplatedMailJob::class, function ($job) use ($student) {
            return $job->event === NotificationService::EVENT_BATCH_ASSIGNED
                && $job->userId === $student->id;
        });
        Queue::assertPushed(SendTemplatedMailJob::class, function ($job) use ($tutor) {
            return $job->event === NotificationService::EVENT_BATCH_ASSIGNED
                && $job->userId === $tutor->id;
        });
    }

    public function test_batch_transferred_notifies_student_once(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $tutor = User::factory()->create(['role' => 'tutor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();
        $fromBatch = $this->createBatchWithTutor($course, $tutor);
        $toBatch = $this->createBatchWithTutor($course, $tutor);
        BatchStudent::create([
            'batch_id' => $fromBatch->id, 'user_id' => $student->id,
            'status' => 'active', 'joined_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')->postJson(
            "/api/admin/batches/{$fromBatch->id}/students/{$student->id}/transfer",
            [
                'to_batch_id' => $toBatch->id,
                'reason' => 'Schedule change',
                'override_reason' => 'Verified offline payment receipt RCPT-2.',
            ]
        )->assertStatus(200);

        Queue::assertPushed(SendTemplatedMailJob::class, function ($job) use ($student, $toBatch) {
            if ($job->event !== NotificationService::EVENT_BATCH_TRANSFERRED || $job->userId !== $student->id) {
                return false;
            }
            $membership = BatchStudent::find($job->ref['membership_id'] ?? null);

            return $membership && (int) $membership->batch_id === (int) $toBatch->id;
        });
    }

    public function test_batch_discontinued_notifies_student(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $tutor = User::factory()->create(['role' => 'tutor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();
        $batch = $this->createBatchWithTutor($course, $tutor);
        BatchStudent::create([
            'batch_id' => $batch->id, 'user_id' => $student->id,
            'status' => 'active', 'joined_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')->postJson(
            "/api/admin/batches/{$batch->id}/students/{$student->id}/discontinue",
            ['reason' => 'Personal reasons']
        )->assertStatus(200);

        Queue::assertPushed(SendTemplatedMailJob::class, function ($job) use ($student) {
            return $job->event === NotificationService::EVENT_BATCH_DISCONTINUED
                && $job->userId === $student->id;
        });
    }

    // -----------------------------------------------------------------
    // E. Certificate
    // -----------------------------------------------------------------

    public function test_certificate_issued_notifies_student_once(): void
    {
        Queue::fake();

        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();
        $section = Section::create([
            'course_id' => $course->id, 'title' => 'Module',
            'slug' => 'module-' . Str::random(4), 'sort_order' => 0, 'is_published' => true,
        ]);
        $lesson = Lesson::create([
            'course_id' => $course->id, 'section_id' => $section->id,
            'title' => 'Final Lesson', 'slug' => 'final-' . Str::random(4),
            'type' => 'video', 'is_published' => true,
        ]);
        $this->createEnrollment($student, $course);
        LessonProgress::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'lesson_id' => $lesson->id, 'section_id' => $section->id,
            'completed' => true, 'completed_at' => now(),
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/certificate");
        $response->assertCreated();

        Queue::assertPushed(SendTemplatedMailJob::class, 1);
        Queue::assertPushed(SendTemplatedMailJob::class, function ($job) use ($student, $response) {
            return $job->event === NotificationService::EVENT_CERTIFICATE_ISSUED
                && $job->userId === $student->id
                && $response->json('certificate.certificate_code') !== null;
        });

        // Re-requesting the existing certificate stays silent.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/certificate")
            ->assertStatus(200);

        Queue::assertPushed(SendTemplatedMailJob::class, 1);
    }

    // -----------------------------------------------------------------
    // F. Live class (scheduled / updated / cancelled)
    // -----------------------------------------------------------------

    private function createLiveClass(User $tutor, Course $course): array
    {
        $response = $this->actingAs($tutor, 'sanctum')->postJson(
            "/api/tutor/courses/{$course->id}/live-classes",
            [
                'title' => 'Notify Live Kickoff',
                'class_date' => now()->addDay()->toDateString(),
                'start_time' => '10:00',
            ]
        );
        $response->assertStatus(201);

        return [$response->json('live_class.id'), $response];
    }

    public function test_live_class_lifecycle_notifies_enrolled_students(): void
    {
        Queue::fake();

        $tutor = User::factory()->create(['role' => 'tutor']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse(['instructor_id' => $tutor->id]);
        $this->createEnrollment($student, $course);

        [$liveClassId] = $this->createLiveClass($tutor, $course);

        Queue::assertPushed(SendTemplatedMailJob::class, 1);
        Queue::assertPushed(SendTemplatedMailJob::class, function ($job) use ($student) {
            return $job->event === NotificationService::EVENT_LIVE_CLASS_SCHEDULED
                && $job->userId === $student->id;
        });

        $this->actingAs($tutor, 'sanctum')->putJson("/api/tutor/live-classes/{$liveClassId}", [
            'title' => 'Notify Live Kickoff Renamed',
        ])->assertStatus(200);

        Queue::assertPushed(SendTemplatedMailJob::class, 2);
        Queue::assertPushed(SendTemplatedMailJob::class, function ($job) use ($student) {
            return $job->event === NotificationService::EVENT_LIVE_CLASS_UPDATED
                && $job->userId === $student->id;
        });

        $this->actingAs($tutor, 'sanctum')->deleteJson("/api/tutor/live-classes/{$liveClassId}")
            ->assertStatus(200);

        Queue::assertPushed(SendTemplatedMailJob::class, 3);
        Queue::assertPushed(SendTemplatedMailJob::class, function ($job) use ($student) {
            return $job->event === NotificationService::EVENT_LIVE_CLASS_CANCELLED
                && $job->userId === $student->id
                && str_contains((string) ($job->ref['snapshot']['title'] ?? ''), 'Renamed');
        });
    }

    public function test_rendered_live_class_mail_contains_no_secrets(): void
    {
        Mail::fake();

        $student = User::factory()->create(['role' => 'student']);
        $tutor = User::factory()->create(['role' => 'tutor']);
        $course = $this->createCourse();
        $liveClass = \App\Models\LiveClass::create([
            'course_id' => $course->id,
            'instructor_id' => $tutor->id,
            'title' => 'Render Check Live',
            'class_date' => now()->addDay()->toDateString(),
            'start_time' => '11:00',
            'status' => 'scheduled',
        ]);

        $this->runJob(
            NotificationService::EVENT_LIVE_CLASS_SCHEDULED,
            $student->id,
            ['live_class_id' => $liveClass->id]
        );

        Mail::assertSent(TemplatedNotificationMail::class, function ($mail) use ($student) {
            $blob = strtolower($mail->subjectLine . ' ' . $mail->htmlBody);

            foreach (['token', 'secret', 'api key', 'webhook', 'password', 'jwt'] as $needle) {
                if (str_contains($blob, $needle)) {
                    return false;
                }
            }

            return str_contains($mail->htmlBody, 'Render Check Live') && $mail->hasTo($student->email);
        });
    }

    public function test_rendered_payment_mail_contains_no_secrets(): void
    {
        Mail::fake();

        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();
        $transaction = PaymentTransaction::create([
            'provider' => 'stub',
            'order_id' => 'order_render_1',
            'payment_id' => 'pay_render_1',
            'idempotency_key' => 'render-key-1',
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount_paise' => 25000,
            'currency' => 'INR',
            'status' => 'paid',
        ]);

        $this->runJob(
            NotificationService::EVENT_PAYMENT_CONFIRMED,
            $student->id,
            ['transaction_id' => $transaction->id]
        );

        Mail::assertSent(TemplatedNotificationMail::class, function ($mail) use ($student) {
            $blob = strtolower($mail->subjectLine . ' ' . $mail->htmlBody);

            foreach (['signature', 'secret', 'api key', 'token', 'password', 'authorization'] as $needle) {
                if (str_contains($blob, $needle)) {
                    return false;
                }
            }

            return str_contains($mail->htmlBody, 'order_render_1') && $mail->hasTo($student->email);
        });
    }

    // -----------------------------------------------------------------
    // G+H. Transaction rollback + delivery failure
    // -----------------------------------------------------------------

    public function test_rolled_back_enrollment_produces_no_mail(): void
    {
        Mail::fake();
        Queue::fake();

        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();

        // Record a dispatch, then roll the enrollment back for real: the
        // queued job must discard the stale reference instead of sending.
        \Illuminate\Support\Facades\DB::beginTransaction();
        $enrollment = $this->createEnrollment($student, $course);
        NotificationService::enrollmentCreated($enrollment);
        \Illuminate\Support\Facades\DB::rollBack();

        $this->assertDatabaseMissing('course_enrollments', ['id' => $enrollment->id]);

        $pushed = null;
        Queue::assertPushed(SendTemplatedMailJob::class, function ($job) use (&$pushed) {
            $pushed = $job;

            return true;
        });
        $pushed->handle();

        Mail::assertNothingSent();
    }

    public function test_business_operation_succeeds_without_synchronous_delivery(): void
    {
        Queue::fake();
        Mail::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();

        // Delivery never runs inside the request: the operation succeeds
        // even if the mailer would fail, and nothing is sent synchronously.
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
        ])->assertStatus(201);

        Mail::assertNothingSent();
        Queue::assertPushed(SendTemplatedMailJob::class, 1);
    }

    public function test_job_failure_propagates_for_retry_semantics(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $enrollment = $this->createEnrollment($student, $this->createCourse());

        \Illuminate\Support\Facades\Mail::shouldReceive('to')
            ->once()
            ->andThrow(new \Exception('smtp down'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('smtp down');

        (new SendTemplatedMailJob(
            NotificationService::EVENT_ENROLLMENT_CREATED,
            $student->id,
            ['enrollment_id' => $enrollment->id]
        ))->handle();
    }

    public function test_rendered_enrollment_mail_contains_no_secrets(): void
    {
        Mail::fake();

        $student = User::factory()->create(['role' => 'student', 'name' => 'Notify Student']);
        $enrollment = $this->createEnrollment($student, $this->createCourse(['title' => 'Notify Course']));

        $this->runJob(
            NotificationService::EVENT_ENROLLMENT_CREATED,
            $student->id,
            ['enrollment_id' => $enrollment->id]
        );

        Mail::assertSent(TemplatedNotificationMail::class, function ($mail) use ($student) {
            $blob = strtolower($mail->subjectLine . ' ' . $mail->htmlBody);

            foreach (['password', 'otp', 'token', 'secret', 'signature', 'authorization', 'session'] as $needle) {
                if (str_contains($blob, $needle)) {
                    return false;
                }
            }

            return str_contains($mail->htmlBody, 'Notify Course')
                && str_contains($mail->htmlBody, $student->email) === false
                && $mail->hasTo($student->email);
        });
    }
}
