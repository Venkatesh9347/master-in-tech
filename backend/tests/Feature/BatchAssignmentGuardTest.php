<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Guards shared by every admission path (CRM conversion, enquiry pipeline,
 * admin enrollment): closed batches refuse members, capped batches refuse
 * new seats once full — as 422, never 500. Plus: SVG uploads are rejected
 * (stored-XSS surface via public storage).
 */
class BatchAssignmentGuardTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourse(string $slug): Course
    {
        return Course::create([
            'title' => 'Guard Fixture',
            'slug' => $slug,
            'description' => 'Capacity guard fixture.',
            'category' => 'Full Stack',
            'instructor' => 'Fixture',
            'duration' => '4 Weeks',
            'difficulty' => 'Beginner',
            'price' => 9999,
            'is_published' => true,
        ]);
    }

    private function makeBatch(Course $course, array $overrides = []): Batch
    {
        return Batch::create(array_merge([
            'name' => 'Guard Batch',
            'code' => 'RIT(GUARD)BC' . random_int(100000, 999999),
            'course_id' => $course->id,
            'start_date' => '2026-01-01',
            'status' => 'ongoing',
        ], $overrides));
    }

    private function occupySeat(Batch $batch): User
    {
        $student = User::factory()->create(['role' => 'student']);
        BatchStudent::create([
            'user_id' => $student->id,
            'batch_id' => $batch->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $student;
    }

    public function test_crm_convert_to_full_batch_returns_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->makeCourse('guard-crm-' . uniqid());
        $batch = $this->makeBatch($course, ['max_students' => 1]);
        $this->occupySeat($batch);

        $lead = Enquiry::create([
            'name' => 'Full Batch Lead',
            'email' => 'fullbatch@example.com',
            'phone' => '9876543210',
            'course_name' => 'Guard Fixture',
            'status' => 'new',
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/crm/leads/{$lead->id}/convert", [
            'course_id' => $course->id,
            'batch_id' => $batch->id,
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => "Cohort batch {$batch->code} is full (1 seats)."]);

        // Rolled back: no user, no enrollment, no membership.
        $this->assertDatabaseMissing('users', ['email' => 'fullbatch@example.com']);
        $this->assertSame(1, BatchStudent::where('batch_id', $batch->id)->count());
    }

    public function test_crm_convert_to_closed_batch_returns_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->makeCourse('guard-closed-' . uniqid());
        $batch = $this->makeBatch($course, ['status' => 'completed']);

        $lead = Enquiry::create([
            'name' => 'Closed Batch Lead',
            'email' => 'closedbatch@example.com',
            'phone' => '9876543210',
            'course_name' => 'Guard Fixture',
            'status' => 'new',
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/crm/leads/{$lead->id}/convert", [
            'course_id' => $course->id,
            'batch_id' => $batch->id,
        ])->assertStatus(422);
    }

    public function test_admin_enrollment_to_full_batch_returns_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->makeCourse('guard-admin-' . uniqid());
        $batch = $this->makeBatch($course, ['max_students' => 1]);
        $this->occupySeat($batch);

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/enrollments', [
            'email' => 'newseat@example.com',
            'name' => 'New Seat',
            'course_id' => $course->id,
            'batch_id' => $batch->id,
        ])->assertStatus(422)
            ->assertJsonPath('errors.batch_id.0', "Cohort batch {$batch->code} is full (1 seats).");
    }

    public function test_enquiry_enroll_to_full_batch_returns_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->makeCourse('guard-enq-' . uniqid());
        $batch = $this->makeBatch($course, ['max_students' => 1]);
        $this->occupySeat($batch);

        $lead = Enquiry::create([
            'name' => 'Pipeline Lead',
            'email' => 'pipeline@example.com',
            'phone' => '9876543210',
            'course_name' => 'Guard Fixture',
            'status' => 'new',
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/enquiries/{$lead->id}/enroll", [
            'course_id' => $course->id,
            'batch_id' => $batch->id,
        ])->assertStatus(422);
    }

    public function test_reactivation_of_existing_member_ignores_capacity(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->makeCourse('guard-react-' . uniqid());
        $batch = $this->makeBatch($course, ['max_students' => 1]);

        // Seat holder discontinued; a newcomer takes the only seat.
        $former = $this->occupySeat($batch);
        BatchStudent::where('batch_id', $batch->id)->where('user_id', $former->id)
            ->update(['status' => 'discontinued', 'left_at' => now()]);
        $newcomer = $this->occupySeat($batch);

        // The former member re-enrolling via admin must be refused (full),
        // while the current holder re-seating is a no-op success.
        Sanctum::actingAs($admin);

        $service = app(\App\Services\EnrollmentAssignmentService::class);
        // Current active holder re-seating consumes no new seat: no exception.
        $service->assignToBatch($newcomer, $batch->fresh());
        $this->assertTrue(true);

        // Former (discontinued) member rejoining a full batch is refused.
        try {
            $service->assignToBatch($former, $batch->fresh());
            $this->fail('Expected BatchAssignmentException for a full batch.');
        } catch (\App\Services\BatchAssignmentException $e) {
            $this->assertStringContainsString('is full', $e->getMessage());
        }
    }

    public function test_svg_upload_is_rejected(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/media', [
            'file' => UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml'),
        ])->assertStatus(422);
    }

    public function test_allowed_image_upload_still_accepted(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/media', [
            'file' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertStatus(201);
    }

    public function test_legacy_progress_requires_enrollment(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->makeCourse('guard-progress-' . uniqid());
        $outsider = User::factory()->create(['role' => 'student']);

        Sanctum::actingAs($outsider);
        $this->getJson("/api/courses/{$course->id}/progress")
            ->assertForbidden()
            ->assertJson(['message' => 'Enrollment required to view course progress.']);

        $member = User::factory()->create(['role' => 'student']);
        \App\Models\CourseEnrollment::create([
            'user_id' => $member->id, 'course_id' => $course->id,
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($member);
        $this->getJson("/api/courses/{$course->id}/progress")
            ->assertOk()
            ->assertJsonStructure(['completed_lessons', 'progress_percentage', 'completed_count', 'total_lessons']);

        // Dropped enrollment loses access, mirroring the lesson gates.
        $member2 = User::factory()->create(['role' => 'student']);
        \App\Models\CourseEnrollment::create([
            'user_id' => $member2->id, 'course_id' => $course->id,
            'status' => 'dropped', 'enrolled_at' => now(),
        ]);
        Sanctum::actingAs($member2);
        $this->getJson("/api/courses/{$course->id}/progress")->assertForbidden();
    }

    public function test_service_reactivation_writes_transfer_history(): void
    {
        $course = $this->makeCourse('guard-react-hist-' . uniqid());
        $batch = $this->makeBatch($course);
        $student = $this->occupySeat($batch);
        \App\Models\BatchStudent::where('batch_id', $batch->id)->where('user_id', $student->id)
            ->update(['status' => 'discontinued', 'left_at' => now()]);

        app(\App\Services\EnrollmentAssignmentService::class)
            ->assignToBatch($student, $batch->fresh(), performedBy: null, reason: 'Reactivation check');

        $this->assertDatabaseHas('batch_transfers', [
            'user_id' => $student->id,
            'to_batch_id' => $batch->id,
            'action_type' => 'rejoined',
        ]);
    }

    public function test_remove_student_writes_transfer_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->makeCourse('guard-remove-' . uniqid());
        $batch = $this->makeBatch($course);
        $student = $this->occupySeat($batch);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/admin/batches/{$batch->id}/students/{$student->id}")
            ->assertOk();

        $this->assertDatabaseHas('batch_transfers', [
            'user_id' => $student->id,
            'from_batch_id' => $batch->id,
            'action_type' => 'removed',
        ]);
        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $batch->id,
            'user_id' => $student->id,
            'status' => 'removed',
        ]);
    }

    public function test_prune_command_removes_long_expired_otps_only(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        \App\Models\StudentLoginOtp::create([
            'user_id' => $user->id,
            'temp_token_hash' => hash('sha256', 'old-token'),
            'otp_hash' => \Illuminate\Support\Facades\Hash::make('123456'),
            'expires_at' => now()->subHours(2),
            'resend_available_at' => now()->subHours(2),
        ]);
        \App\Models\StudentLoginOtp::create([
            'user_id' => $user->id,
            'temp_token_hash' => hash('sha256', 'fresh-token'),
            'otp_hash' => \Illuminate\Support\Facades\Hash::make('654321'),
            'expires_at' => now()->addSeconds(20),
            'resend_available_at' => now()->addSeconds(30),
        ]);

        $redis = $this->mock(\App\Services\Infrastructure\RedisHealthService::class, function ($mock) {
            $mock->shouldReceive('acquireLock')->once()->andReturn(true);
            $mock->shouldReceive('releaseLock')->once();
        });

        $this->artisan('mit:prune-stale-sessions')->assertSuccessful();

        $this->assertDatabaseMissing('student_login_otps', ['temp_token_hash' => hash('sha256', 'old-token')]);
        $this->assertDatabaseHas('student_login_otps', ['temp_token_hash' => hash('sha256', 'fresh-token')]);
    }
}
