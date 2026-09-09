<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStudentEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private function createCourse(array $attributes = []): Course
    {
        $title = $attributes['title'] ?? 'Course ' . Str::random(5);
        return Course::create(array_merge([
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => 'Comprehensive technical training course description.',
            'category' => 'Engineering',
            'instructor' => 'Senior Specialist',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ], $attributes));
    }

    public function test_guest_and_unauthorized_users_cannot_access_admin_enrollments(): void
    {
        // 1. Guest
        $this->getJson('/api/admin/enrollments')->assertStatus(401);
        $this->getJson('/api/admin/enrollments/stats')->assertStatus(401);
        $this->postJson('/api/admin/enrollments', [])->assertStatus(401);

        // 2. Student
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        $this->getJson('/api/admin/enrollments')->assertStatus(403);
        $this->getJson('/api/admin/enrollments/stats')->assertStatus(403);
        $this->postJson('/api/admin/enrollments', [])->assertStatus(403);

        // 3. Tutor
        $tutor = User::factory()->create(['role' => 'tutor']);
        Sanctum::actingAs($tutor);

        $this->getJson('/api/admin/enrollments')->assertStatus(403);
        $this->getJson('/api/admin/enrollments/stats')->assertStatus(403);
        $this->postJson('/api/admin/enrollments', [])->assertStatus(403);
    }

    public function test_admin_can_view_enrollment_statistics(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student1 = User::factory()->create(['role' => 'student']);
        $student2 = User::factory()->create(['role' => 'student']);

        $course1 = $this->createCourse([
            'title' => 'Full Stack Cloud Mastery',
            'category' => 'Cloud',
            'instructor' => 'Lead Architect',
        ]);

        $course2 = $this->createCourse([
            'title' => 'AI Engineering Bootcamp',
            'category' => 'AI',
            'instructor' => 'AI Scientist',
        ]);

        CourseEnrollment::create([
            'user_id' => $student1->id,
            'course_id' => $course1->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        CourseEnrollment::create([
            'user_id' => $student1->id,
            'course_id' => $course2->id,
            'status' => 'completed',
            'enrolled_at' => now(),
        ]);

        CourseEnrollment::create([
            'user_id' => $student2->id,
            'course_id' => $course1->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/admin/enrollments/stats');
        $res->assertStatus(200)
            ->assertJson([
                'total_enrollments' => 3,
                'active_enrollments' => 2,
                'completed_enrollments' => 1,
                'unique_students' => 2,
            ]);
    }

    public function test_admin_can_list_all_enrollments_with_search_and_filters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studentAlice = User::factory()->create([
            'name' => 'Alice Johnson',
            'email' => 'alice@masterintech.test',
            'student_id' => 'STU-1001',
            'role' => 'student',
        ]);
        $studentBob = User::factory()->create([
            'name' => 'Bob Smith',
            'email' => 'bob@masterintech.test',
            'student_id' => 'STU-1002',
            'role' => 'student',
        ]);

        $reactCourse = $this->createCourse([
            'title' => 'React & Next.js Architecture',
            'category' => 'Frontend',
            'instructor' => 'Frontend Lead',
        ]);

        $devOpsCourse = $this->createCourse([
            'title' => 'Kubernetes & Docker Mastery',
            'category' => 'DevOps',
            'instructor' => 'DevOps Specialist',
        ]);

        $enrollmentAlice = CourseEnrollment::create([
            'user_id' => $studentAlice->id,
            'course_id' => $reactCourse->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        CourseEnrollment::create([
            'user_id' => $studentBob->id,
            'course_id' => $devOpsCourse->id,
            'status' => 'completed',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        // 1. List all
        $resAll = $this->getJson('/api/admin/enrollments');
        $resAll->assertStatus(200);
        $this->assertCount(2, $resAll->json());

        // 2. Search by student name
        $resSearch = $this->getJson('/api/admin/enrollments?search=Alice');
        $resSearch->assertStatus(200);
        $this->assertCount(1, $resSearch->json());
        $this->assertEquals($studentAlice->id, $resSearch->json('0.user.id'));

        // 3. Search by student ID
        $resSearchId = $this->getJson('/api/admin/enrollments?search=STU-1001');
        $resSearchId->assertStatus(200);
        $this->assertCount(1, $resSearchId->json());

        // 4. Search by course category
        $resSearchCategory = $this->getJson('/api/admin/enrollments?search=DevOps');
        $resSearchCategory->assertStatus(200);
        $this->assertCount(1, $resSearchCategory->json());
        $this->assertEquals($studentBob->id, $resSearchCategory->json('0.user.id'));

        // 5. Filter by status
        $resFilterStatus = $this->getJson('/api/admin/enrollments?status=completed');
        $resFilterStatus->assertStatus(200);
        $this->assertCount(1, $resFilterStatus->json());
        $this->assertEquals('completed', $resFilterStatus->json('0.status'));

        // 6. Filter by course_id
        $resFilterCourse = $this->getJson("/api/admin/enrollments?course_id={$reactCourse->id}");
        $resFilterCourse->assertStatus(200);
        $this->assertCount(1, $resFilterCourse->json());
    }

    public function test_admin_can_view_specific_student_enrollments_with_progress(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student', 'name' => 'Sara Connor']);

        $course = $this->createCourse([
            'title' => 'Cybersecurity Analyst Training',
            'category' => 'Security',
            'instructor' => 'Chief Security Officer',
        ]);

        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1: Fundamentals',
            'order' => 1,
            'is_published' => true,
        ]);

        $lesson1 = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Threat Modeling',
            'slug' => 'threat-modeling',
            'order' => 1,
            'is_published' => true,
        ]);

        $lesson2 = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Network Defense',
            'slug' => 'network-defense',
            'order' => 2,
            'is_published' => true,
        ]);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        LessonProgress::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson1->id,
            'completed' => true,
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $res = $this->getJson("/api/admin/students/{$student->id}/enrollments");
        $res->assertStatus(200);
        $this->assertCount(1, $res->json());

        $item = $res->json('0');
        $this->assertEquals($course->id, $item['course']['id']);
        $this->assertEquals(2, $item['total_lessons']);
        $this->assertEquals(1, $item['completed_lessons']);
        $this->assertEquals(50.0, $item['calculated_progress_percentage']);
    }

    public function test_admin_can_assign_existing_course_to_student(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create([
            'role' => 'student',
            'name' => 'David Warner',
            'student_id' => 'STU-1005',
        ]);

        $course = $this->createCourse([
            'title' => 'Python for Data Science & ML',
            'category' => 'Data Science',
            'instructor' => 'Dr. Py',
        ]);

        Sanctum::actingAs($admin);

        $res = $this->postJson('/api/admin/enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $res->assertStatus(201)
            ->assertJsonFragment([
                'message' => 'Course successfully assigned to student.',
            ]);

        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
    }

    public function test_duplicate_course_assignment_is_prevented_with_validation_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);

        $course = $this->createCourse([
            'title' => 'AWS Cloud Architect',
            'category' => 'Cloud',
            'instructor' => 'AWS Lead',
        ]);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $res = $this->postJson('/api/admin/enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
        ]);

        $res->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Student is already enrolled in this course.',
            ]);

        $this->assertEquals(1, CourseEnrollment::where('user_id', $student->id)->where('course_id', $course->id)->count());
    }

    public function test_assigned_course_immediately_appears_in_student_dashboard_my_courses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student', 'name' => 'Emma Watson']);

        $course = $this->createCourse([
            'title' => 'Fullstack Laravel & Vue Mastery',
            'category' => 'Web Development',
            'instructor' => 'Senior Engineer',
        ]);

        // 1. Admin assigns course to student
        Sanctum::actingAs($admin);
        $resAssign = $this->postJson('/api/admin/enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
        ]);
        $resAssign->assertStatus(201);

        // 2. Student accesses my-courses endpoint (used by Student Dashboard)
        Sanctum::actingAs($student);
        $resStudent = $this->getJson('/api/my-courses');
        $resStudent->assertStatus(200);

        $courses = $resStudent->json();
        $this->assertCount(1, $courses);
        $this->assertEquals($course->id, $courses[0]['course_id']);
        $this->assertEquals('Fullstack Laravel & Vue Mastery', $courses[0]['course']['title']);
        $this->assertEquals('active', $courses[0]['status']);
    }

    public function test_admin_can_update_enrollment_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);

        $course = $this->createCourse([
            'title' => 'Golang Microservices',
        ]);

        $enrollment = CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $res = $this->putJson("/api/admin/enrollments/{$enrollment->id}", [
            'status' => 'completed',
        ]);

        $res->assertStatus(200)
            ->assertJsonFragment([
                'message' => 'Enrollment status updated successfully.',
            ]);

        $this->assertEquals('completed', $enrollment->fresh()->status);
    }

    public function test_admin_can_remove_enrollment_and_it_immediately_disappears_from_student_dashboard(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);

        $course = $this->createCourse([
            'title' => 'DevOps Automation CI/CD',
        ]);

        $enrollment = CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        // Delete enrollment
        $resDelete = $this->deleteJson("/api/admin/enrollments/{$enrollment->id}");
        $resDelete->assertStatus(200)
            ->assertJsonFragment([
                'message' => 'Student enrollment removed successfully.',
            ]);

        $this->assertDatabaseMissing('course_enrollments', [
            'id' => $enrollment->id,
        ]);

        // Student accesses my-courses -> empty
        Sanctum::actingAs($student);
        $resStudent = $this->getJson('/api/my-courses');
        $resStudent->assertStatus(200);
        $this->assertEmpty($resStudent->json());
    }

    public function test_single_active_session_is_enforced_on_admin_enrollment_endpoints(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'current_session_id' => 'session_device_A',
        ]);

        // Create token bound to session_device_B (which has been superseded/revoked by device A)
        $token = $admin->createToken('test_token', ['session:session_device_B'])->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/enrollments');

        $res->assertStatus(401)
            ->assertJsonFragment([
                'code' => 'SESSION_REVOKED',
            ]);
    }

    public function test_admin_enrollment_assigns_batch_membership_when_batch_provided(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse([
            'title' => 'Cloud Native Cohort Course',
            'slug' => 'cloud-native-cohort-' . Str::random(4),
            'code' => 'CNC',
            'priority' => 10,
        ]);
        $batch = \App\Models\Batch::create([
            'name' => 'Cloud Native Cohort',
            'code' => 'RIT(CN)BC010826',
            'course_id' => $course->id,
            'start_date' => '2026-10-01',
            'status' => 'upcoming',
        ]);

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

    public function test_admin_enrollment_rejects_batch_from_different_course(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse(['title' => 'Course A']);
        $otherCourse = $this->createCourse(['title' => 'Course B']);
        $batch = \App\Models\Batch::create([
            'name' => 'Wrong Cohort',
            'code' => 'RIT(W)BC010826',
            'course_id' => $otherCourse->id,
            'start_date' => '2026-10-01',
            'status' => 'upcoming',
        ]);

        Sanctum::actingAs($admin);

        $res = $this->postJson('/api/admin/enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'batch_id' => $batch->id,
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['batch_id']);
        $this->assertDatabaseMissing('batch_students', ['user_id' => $student->id]);
    }

    public function test_admin_enrollment_rejects_statuses_not_in_db_enum(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createCourse(['title' => 'Enum Alignment Course']);

        Sanctum::actingAs($admin);

        // Phantom statuses that the DB enum does not allow must be rejected as 422.
        foreach (['pending', 'cancelled'] as $phantom) {
            $res = $this->postJson('/api/admin/enrollments', [
                'user_id' => $student->id,
                'course_id' => $course->id,
                'status' => $phantom,
            ]);
            $res->assertStatus(422)
                ->assertJsonValidationErrors(['status']);
        }

        // Valid member of the enum persists cleanly.
        $resOk = $this->postJson('/api/admin/enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'dropped',
        ]);
        $resOk->assertStatus(201);
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'dropped',
        ]);
    }
}
