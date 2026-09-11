<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\BatchTransfer;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\EnrollmentAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrollmentAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private EnrollmentAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EnrollmentAssignmentService();
    }

    private function createCourse(): Course
    {
        $title = 'Course ' . Str::random(5);
        return Course::create([
            'title' => $title,
            'slug' => Str::slug($title),
            'code' => 'AI',
            'description' => 'Comprehensive technical training course description.',
            'category' => 'Artificial Intelligence',
            'instructor' => 'Senior Specialist',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ]);
    }

    public function test_ensure_student_user_provisions_new_student_account(): void
    {
        $user = $this->service->ensureStudentUser('Ravi Kumar', 'ravi.kumar@example.com', '+91 9000000001');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Ravi Kumar',
            'email' => 'ravi.kumar@example.com',
            'phone' => '+91 9000000001',
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->assertSame('STU-' . (1000 + $user->id), $user->student_id);
    }

    public function test_ensure_student_user_preserves_staff_role(): void
    {
        $existing = User::factory()->create(['email' => 'staff@example.com', 'role' => 'tutor', 'status' => 'active']);

        $user = $this->service->ensureStudentUser('Dr. Mentor', 'staff@example.com');

        $this->assertSame($existing->id, $user->id);
        $this->assertSame('tutor', $user->role);
        $this->assertEquals(1, User::where('email', 'staff@example.com')->count());
    }

    public function test_ensure_student_user_phone_backfilled_on_existing_student(): void
    {
        $existing = User::factory()->create(['email' => 'old.student@example.com', 'role' => 'student', 'phone' => null]);

        $user = $this->service->ensureStudentUser('Old Student', 'old.student@example.com', '9988776655');

        $this->assertSame($existing->id, $user->id);
        $this->assertSame('9988776655', $user->phone);
        $this->assertSame('STU-' . (1000 + $existing->id), $user->student_id);
    }

    public function test_ensure_active_enrollment_activates_dropped_enrollment(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();

        $dropped = CourseEnrollment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'dropped',
            'enrolled_at' => now()->subDay(),
            'progress_percentage' => 0,
        ]);

        $enrollment = $this->service->ensureActiveEnrollment($user, $course);

        $this->assertSame($dropped->id, $enrollment->id);
        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertEquals(1, CourseEnrollment::where('user_id', $user->id)->where('course_id', $course->id)->count());
    }

    public function test_assign_to_batch_creates_membership_and_audit_trail(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();
        $batch = Batch::create([
            'name' => 'September Cohort',
            'code' => 'RIT(AI)BC010926',
            'course_id' => $course->id,
            'tutor_id' => null,
            'start_date' => '2026-09-01',
            'status' => 'upcoming',
            'max_students' => 25,
        ]);
        $actor = User::factory()->create(['role' => 'counsellor']);

        $membership = $this->service->assignToBatch(
            $user,
            $batch,
            performedBy: $actor->id,
            reason: 'Direct CRM lead conversion to cohort',
            notes: 'Admitted & enrolled from CRM conversion',
        );

        $this->assertDatabaseHas('batch_students', [
            'id' => $membership->id,
            'batch_id' => $batch->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('batch_transfers', [
            'user_id' => $user->id,
            'from_batch_id' => null,
            'to_batch_id' => $batch->id,
            'action_type' => 'enrolled',
            'performed_by' => $actor->id,
        ]);
    }

    public function test_assign_to_batch_is_idempotent(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();
        $batch = Batch::create([
            'name' => 'Idempotent Cohort',
            'code' => 'RIT(AI)BC020926',
            'course_id' => $course->id,
            'tutor_id' => null,
            'start_date' => '2026-09-01',
            'status' => 'upcoming',
            'max_students' => 25,
        ]);

        $this->service->assignToBatch($user, $batch, performedBy: null, reason: 'First assignment');
        $this->service->assignToBatch($user, $batch, performedBy: null, reason: 'Second assignment');

        $this->assertEquals(1, BatchStudent::where('batch_id', $batch->id)->where('user_id', $user->id)->count());
        $this->assertEquals(1, BatchTransfer::where('to_batch_id', $batch->id)->where('user_id', $user->id)->count());
    }

    public function test_assign_to_batch_reactivates_deactivated_membership_without_rewriting_history(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse();
        $batch = Batch::create([
            'name' => 'Reactivation Cohort',
            'code' => 'RIT(AI)BC030926',
            'course_id' => $course->id,
            'tutor_id' => null,
            'start_date' => '2026-09-01',
            'status' => 'active',
            'max_students' => 25,
        ]);

        $this->service->assignToBatch($user, $batch, performedBy: null, reason: 'Initial');

        BatchStudent::where('batch_id', $batch->id)->where('user_id', $user->id)->update([
            'status' => 'discontinued',
            'discontinued_at' => now(),
        ]);

        $membership = $this->service->assignToBatch($user, $batch, performedBy: null, reason: 'Re-admission');

        $this->assertSame('active', $membership->fresh()->status);
        $this->assertNull($membership->fresh()->discontinued_at);
        $this->assertEquals(1, BatchStudent::where('batch_id', $batch->id)->where('user_id', $user->id)->count());
        // Reactivation reuses the membership row but appends a rejoined
        // transfer so the seat re-take is visible in batch history.
        $this->assertEquals(2, BatchTransfer::where('to_batch_id', $batch->id)->where('user_id', $user->id)->count());
        $this->assertSame('rejoined', BatchTransfer::where('to_batch_id', $batch->id)->where('user_id', $user->id)->latest('id')->value('action_type'));
    }
}