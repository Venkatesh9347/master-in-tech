<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\BatchTransfer;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnrollmentBatchConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function student(): User
    {
        return User::factory()->create(['role' => 'student']);
    }

    private function course(): Course
    {
        return Course::create([
            'title' => 'Batch Consistency Course',
            'slug' => 'batch-consistency-' . uniqid(),
            'description' => 'Desc',
            'instructor' => 'Instructor',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'price' => 19999,
        ]);
    }

    private function batch(Course $course): Batch
    {
        return Batch::create([
            'name' => 'Consistency Cohort',
            'code' => 'CONSISTENCY001',
            'course_id' => $course->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'status' => 'upcoming',
        ]);
    }

    public function test_admin_enrollment_assigns_batch_membership_when_batch_provided(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $course = $this->course();
        $batch = $this->batch($course);
        Sanctum::actingAs($admin);

        $res = $this->postJson('/api/admin/enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'batch_id' => $batch->id,
        ]);

        $res->assertStatus(201);

        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $batch->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('batch_transfers', [
            'user_id' => $student->id,
            'to_batch_id' => $batch->id,
            'action_type' => 'enrolled',
        ]);
    }

    public function test_admin_enrollment_without_batch_creates_no_membership(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $course = $this->course();
        Sanctum::actingAs($admin);

        $res = $this->postJson('/api/admin/enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseMissing('batch_transfers', ['user_id' => $student->id]);
    }

    public function test_admin_enrollment_ignores_batch_from_different_course(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $course = $this->course();
        $otherCourse = $this->course();
        $batch = $this->batch($otherCourse);
        Sanctum::actingAs($admin);

        $res = $this->postJson('/api/admin/enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'batch_id' => $batch->id,
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseMissing('batch_students', ['user_id' => $student->id]);
    }

    public function test_enquiry_enroll_assigns_batch_membership_when_batch_provided(): void
    {
        $admin = $this->admin();
        $course = $this->course();
        $batch = $this->batch($course);
        $enquiry = Enquiry::create([
            'name' => 'Lead Candidate',
            'email' => 'lead@example.com',
            'phone' => '9988776655',
            'course_id' => $course->id,
            'course_title' => $course->title,
            'status' => 'new',
        ]);
        Sanctum::actingAs($admin);

        $res = $this->postJson("/api/admin/enquiries/{$enquiry->id}/enroll", [
            'course_id' => $course->id,
            'batch_id' => $batch->id,
        ]);

        $res->assertStatus(200);
        $student = $enquiry->fresh()->enrolledUser;
        $this->assertNotNull($student);
        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $batch->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('batch_transfers', [
            'user_id' => $student->id,
            'to_batch_id' => $batch->id,
        ]);
    }

    public function test_existing_active_membership_is_not_duplicated(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $course = $this->course();
        $batch = $this->batch($course);
        BatchStudent::create([
            'batch_id' => $batch->id,
            'user_id' => $student->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'batch_id' => $batch->id,
        ])->assertStatus(201);

        $this->assertEquals(
            1,
            BatchStudent::where('batch_id', $batch->id)->where('user_id', $student->id)->count()
        );
        $this->assertDatabaseMissing('batch_transfers', ['user_id' => $student->id]);
    }
}