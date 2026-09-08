<?php

namespace Tests\Feature;

use App\Models\Batch;
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

    private function createCourse(array $overrides = []): Course
    {
        return Course::create(array_merge([
            'title' => 'Full Stack Web Development',
            'slug' => 'full-stack-web-development',
            'description' => 'Full stack course',
            'instructor' => 'Senior Faculty',
            'duration' => '12 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ], $overrides));
    }

    private function createBatch(Course $course, array $overrides = []): Batch
    {
        return Batch::create(array_merge([
            'name' => 'Morning Batch',
            'code' => 'RIT(FSWD)BC010926',
            'course_id' => $course->id,
            'start_date' => '2026-09-01',
            'status' => 'ongoing',
        ], $overrides));
    }

    public function test_enquiry_enroll_syncs_batch_membership_when_batch_provided(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $course = $this->createCourse();
        $batch = $this->createBatch($course);

        $enquiry = Enquiry::create([
            'name' => 'Test Student',
            'email' => 'student@example.com',
            'phone' => '9876543210',
            'course_id' => $course->id,
            'course_title' => $course->title,
            'status' => 'interested',
        ]);

        $res = $this->postJson("/api/admin/enquiries/{$enquiry->id}/enroll", [
            'course_id' => $course->id,
            'batch_id' => $batch->id,
        ]);

        $res->assertOk()
            ->assertJsonFragment([
                'message' => "Student Test Student has been enrolled in {$course->title} with active LMS access.",
            ]);

        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $res->json('user.id'),
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $batch->id,
            'user_id' => $res->json('user.id'),
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('batch_transfers', [
            'to_batch_id' => $batch->id,
            'action_type' => 'enrolled',
        ]);

        $enquiry->refresh();
        $this->assertEquals('enrolled', $enquiry->status);
    }

    public function test_enquiry_enroll_rejects_batch_from_different_course(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $course = $this->createCourse();
        $otherCourse = $this->createCourse([
            'title' => 'Python with AI',
            'slug' => 'python-ai',
        ]);
        $otherBatch = $this->createBatch($otherCourse, [
            'code' => 'RIT(PY)BC010926',
        ]);

        $enquiry = Enquiry::create([
            'name' => 'Test Student',
            'email' => 'student2@example.com',
            'phone' => '9876543210',
            'course_id' => $course->id,
            'course_title' => $course->title,
            'status' => 'interested',
        ]);

        $res = $this->postJson("/api/admin/enquiries/{$enquiry->id}/enroll", [
            'course_id' => $course->id,
            'batch_id' => $otherBatch->id,
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseMissing('batch_students', [
            'batch_id' => $otherBatch->id,
        ]);
        $this->assertDatabaseMissing('course_enrollments', [
            'course_id' => $course->id,
        ]);
    }

    public function test_crm_convert_rejects_batch_from_different_course(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $course = $this->createCourse();
        $otherCourse = $this->createCourse([
            'title' => 'SAP FICO',
            'slug' => 'sap-fico',
        ]);
        $otherBatch = $this->createBatch($otherCourse, [
            'code' => 'RIT(SAP)BC010926',
        ]);

        $lead = Enquiry::create([
            'name' => 'Convert Lead',
            'email' => 'convert@example.com',
            'phone' => '9123456780',
            'course_id' => $course->id,
            'course_title' => $course->title,
            'status' => 'demo_completed',
        ]);

        $res = $this->postJson("/api/admin/crm/leads/{$lead->id}/convert", [
            'course_id' => $course->id,
            'batch_id' => $otherBatch->id,
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseMissing('batch_students', [
            'batch_id' => $otherBatch->id,
        ]);
        $this->assertDatabaseMissing('course_enrollments', [
            'course_id' => $course->id,
        ]);
        $lead->refresh();
        $this->assertNotEquals('converted', $lead->status);
    }

    public function test_crm_convert_batch_sync_remains_consistent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $course = $this->createCourse();
        $batch = $this->createBatch($course);

        $lead = Enquiry::create([
            'name' => 'Consistent Lead',
            'email' => 'consistent@example.com',
            'phone' => '9012345678',
            'course_id' => $course->id,
            'course_title' => $course->title,
            'status' => 'follow_up',
        ]);

        $res = $this->postJson("/api/admin/crm/leads/{$lead->id}/convert", [
            'course_id' => $course->id,
            'batch_id' => $batch->id,
        ]);

        $res->assertOk();

        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $res->json('user.id'),
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $batch->id,
            'user_id' => $res->json('user.id'),
            'status' => 'active',
        ]);
    }

    public function test_enquiry_enroll_without_batch_leaves_no_batch_membership(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $course = $this->createCourse();

        $enquiry = Enquiry::create([
            'name' => 'No Batch Student',
            'email' => 'nobatch@example.com',
            'phone' => '9876543210',
            'course_id' => $course->id,
            'course_title' => $course->title,
            'status' => 'interested',
        ]);

        $res = $this->postJson("/api/admin/enquiries/{$enquiry->id}/enroll", [
            'course_id' => $course->id,
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $res->json('user.id'),
            'course_id' => $course->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('batch_students', 0);
    }
}