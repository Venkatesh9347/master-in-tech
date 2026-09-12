<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LmsEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cannot_self_enroll_in_a_course(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create([
            'title' => 'React Mastery',
            'slug' => 'react-mastery',
            'description' => 'Learn modern React with TypeScript',
            'instructor' => 'John Doe',
            'price' => 4999,
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/enroll");

        $response->assertForbidden();
        $response->assertJsonPath(
            'message',
            'Public self-enrollment is disabled. Student portal access is granted by MasterInTech administration after counselling and admission.'
        );

        $this->assertDatabaseMissing('course_enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_student_cannot_self_enroll_even_when_already_assigned(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create([
            'title' => 'Vue Mastery',
            'slug' => 'vue-mastery',
            'description' => 'Learn Vue 3',
            'instructor' => 'Jane Smith',
            'price' => 3999,
            'duration' => '3 weeks',
            'difficulty' => 'Beginner',
        ]);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $secondResponse = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/enroll");

        $secondResponse->assertForbidden();
        $this->assertEquals(1, CourseEnrollment::where('user_id', $student->id)->where('course_id', $course->id)->count());
    }

    public function test_my_courses_returns_enrolled_courses_for_student(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create([
            'title' => 'Laravel APIs',
            'slug' => 'laravel-apis',
            'description' => 'Building REST APIs',
            'instructor' => 'Taylor',
            'price' => 5999,
            'duration' => '6 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ]);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson('/api/my-courses');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.course_id', $course->id);
    }

    public function test_enrolled_student_can_access_course_and_classroom_progress(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create([
            'title' => 'Full Stack Cloud Masterclass',
            'slug' => 'full-stack-cloud',
            'description' => 'Cloud architecture and development',
            'instructor' => 'Lead Architect',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ]);

        $section = $course->sections()->create([
            'title' => 'Module 1: Cloud Foundations',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $lesson = $section->lessons()->create([
            'course_id' => $course->id,
            'title' => '1.1 Infrastructure Setup',
            'type' => 'video',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        // Enroll student
        $course->enrollments()->create([
            'user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        // Enrolled student accesses course details
        $resCourse = $this->actingAs($student, 'sanctum')->getJson("/api/courses/{$course->id}");
        $resCourse->assertOk()
            ->assertJsonPath('is_enrolled', true);

        // Enrolled student accesses classroom LMS progress
        $resProgress = $this->actingAs($student, 'sanctum')->getJson("/api/courses/{$course->id}/lms-progress");
        $resProgress->assertOk()
            ->assertJsonPath('course_id', $course->id)
            ->assertJsonPath('total_lessons', 1);

        // Enrolled student accesses individual lesson
        $resLesson = $this->actingAs($student, 'sanctum')
            ->getJson("/api/courses/{$course->id}/sections/{$section->id}/lessons/{$lesson->id}");
        $resLesson->assertOk()
            ->assertJsonPath('id', $lesson->id)
            ->assertJsonPath('title', '1.1 Infrastructure Setup');
    }

    public function test_non_enrolled_student_is_denied_access_to_classroom_and_lessons(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create([
            'title' => 'Advanced AI & Machine Learning',
            'slug' => 'advanced-ai-ml',
            'description' => 'Deep neural networks and LLMs',
            'instructor' => 'AI Researcher',
            'duration' => '10 weeks',
            'difficulty' => 'Advanced',
            'is_published' => true,
        ]);

        $section = $course->sections()->create([
            'title' => 'Module 1: Deep Learning',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $lesson = $section->lessons()->create([
            'course_id' => $course->id,
            'title' => '1.1 Backpropagation Math',
            'type' => 'video',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        // Student is NOT enrolled
        $resCourse = $this->actingAs($student, 'sanctum')->getJson("/api/courses/{$course->id}");
        $resCourse->assertOk()
            ->assertJsonPath('is_enrolled', false);

        // Accessing classroom progress is denied
        $resProgress = $this->actingAs($student, 'sanctum')->getJson("/api/courses/{$course->id}/lms-progress");
        $resProgress->assertStatus(403)
            ->assertJsonPath('enrollment_required', true);

        // Accessing individual lesson is denied
        $resLesson = $this->actingAs($student, 'sanctum')
            ->getJson("/api/courses/{$course->id}/sections/{$section->id}/lessons/{$lesson->id}");
        $resLesson->assertStatus(403)
            ->assertJsonPath('enrollment_required', true);
    }

    public function test_student_sees_only_their_own_enrollments(): void
    {
        $studentA = User::factory()->create(['role' => 'student']);
        $studentB = User::factory()->create(['role' => 'student']);

        $courseA = Course::create([
            'title' => 'Course For A',
            'slug' => 'course-a',
            'description' => 'Course A',
            'instructor' => 'Faculty A',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);

        $courseB = Course::create([
            'title' => 'Course For B',
            'slug' => 'course-b',
            'description' => 'Course B',
            'instructor' => 'Faculty B',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);

        $courseA->enrollments()->create([
            'user_id' => $studentA->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $courseB->enrollments()->create([
            'user_id' => $studentB->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        // Student A checks my-courses
        $resA = $this->actingAs($studentA, 'sanctum')->getJson('/api/my-courses');
        $resA->assertOk();
        $this->assertCount(1, $resA->json());
        $this->assertEquals($courseA->id, $resA->json('0.course_id'));

        // Student B checks my-courses
        $resB = $this->actingAs($studentB, 'sanctum')->getJson('/api/my-courses');
        $resB->assertOk();
        $this->assertCount(1, $resB->json());
        $this->assertEquals($courseB->id, $resB->json('0.course_id'));
    }

    public function test_admission_gated_enrollment_regression(): void
    {
        $course = Course::create([
            'title' => 'Cloud Security Foundations',
            'slug' => 'cloud-security-foundations',
            'code' => 'CSF',
            'description' => 'Admission-gated professional programme',
            'instructor' => 'Faculty Lead',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ]);

        // Public visitor: no student self-registration
        $this->postJson('/api/register', [
            'name' => 'Public Visitor',
            'email' => 'public.visitor@example.com',
            'password' => 'secret12345',
            'password_confirmation' => 'secret12345',
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'public.visitor@example.com']);

        // Public visitor: no self-enrollment
        $this->postJson("/api/courses/{$course->id}/enroll")->assertUnauthorized();

        // Public visitor → Demo/Enquiry
        $enquiryRes = $this->postJson('/api/enquiries', [
            'name' => 'Public Visitor',
            'email' => 'public.visitor@example.com',
            'phone' => '9899320575',
            'course_id' => $course->id,
            'message' => 'Requesting a live demo session.',
        ]);
        $enquiryRes->assertCreated();
        $this->assertDatabaseHas('enquiries', [
            'email' => 'public.visitor@example.com',
            'status' => 'new',
        ]);

        // Admin creates student
        $admin = User::factory()->create(['role' => 'admin']);
        $studentPassword = 'AdmittedStudent123!';
        $provisionRes = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/users', [
            'name' => 'Admitted Student',
            'email' => 'admitted.student@example.com',
            'password' => $studentPassword,
            'phone' => '+91 98765 43210',
            'student_id' => 'STU-9001',
            'role' => 'student',
            'status' => 'active',
        ]);
        $provisionRes->assertCreated();
        $studentId = $provisionRes->json('user.id');
        $this->assertNotEmpty($studentId);
        $this->assertDatabaseHas('users', [
            'id' => $studentId,
            'email' => 'admitted.student@example.com',
            'role' => 'student',
            'status' => 'active',
        ]);

        // Admin enrolls student (B3: pending without verified payment).
        $enrollRes = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/enrollments', [
            'user_id' => $studentId,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
        $enrollRes->assertCreated()
            ->assertJsonFragment(['payment_required' => true]);
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $studentId,
            'course_id' => $course->id,
            'status' => 'pending',
        ]);

        // Admin assigns batch
        $batchRes = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/batches', [
            'course_id' => $course->id,
            'start_date' => '2026-08-25',
            'schedule_type' => 'weekdays',
        ]);
        $batchRes->assertCreated();
        $batchId = $batchRes->json('batch.id');
        $batchCode = $batchRes->json('batch.code');
        $this->assertNotEmpty($batchId);
        $this->assertNotEmpty($batchCode);

        // Batch placement is deferred while payment is pending (B3).
        $assignBatchRes = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/batches/{$batchId}/students", [
            'user_id' => $studentId,
        ]);
        $assignBatchRes->assertCreated()
            ->assertJsonFragment(['payment_required' => true]);
        $this->assertDatabaseMissing('batch_students', [
            'batch_id' => $batchId,
            'user_id' => $studentId,
            'status' => 'active',
        ]);

        // Admin override activates the admission and cohort placement.
        $enrollmentId = \App\Models\CourseEnrollment::where('user_id', $studentId)->where('course_id', $course->id)->first()->id;
        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/enrollments/{$enrollmentId}", [
            'status' => 'active',
            'override_reason' => 'Registrar-approved admission for regression cohort.',
        ])->assertOk();

        $assignBatchRes2 = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/batches/{$batchId}/students", [
            'user_id' => $studentId,
        ]);
        $assignBatchRes2->assertCreated();
        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $batchId,
            'user_id' => $studentId,
            'status' => 'active',
        ]);

        $batchDetail = $this->actingAs($admin, 'sanctum')->getJson("/api/admin/batches/{$batchId}");
        $batchDetail->assertOk();
        $this->assertEquals($batchCode, $batchDetail->json('code'));
        $rosterUserIds = collect($batchDetail->json('batch_students'))->pluck('user_id')->all();
        $this->assertContains($studentId, $rosterUserIds);

        $this->actingAsGuest('sanctum');

        // Student can log in
        $loginRes = $this->postJson('/api/login', [
            'email' => 'admitted.student@example.com',
            'password' => $studentPassword,
        ]);
        $loginRes->assertOk();
        $token = $loginRes->json('access_token');
        $this->assertNotEmpty($token);
        $this->assertEquals($studentId, $loginRes->json('user.id'));

        // Student still cannot self-enroll
        $this->withToken($token)
            ->postJson("/api/courses/{$course->id}/enroll")
            ->assertForbidden();

        // Student sees assigned course
        $myCourses = $this->withToken($token)->getJson('/api/my-courses');
        $myCourses->assertOk();
        $this->assertCount(1, $myCourses->json());
        $this->assertEquals($course->id, $myCourses->json('0.course_id'));
        $this->assertEquals('Cloud Security Foundations', $myCourses->json('0.course.title'));
    }
}
