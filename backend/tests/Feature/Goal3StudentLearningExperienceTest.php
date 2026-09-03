<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Goal3StudentLearningExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function createTestCourse(string $title = 'Artificial Intelligence', string $slug = 'artificial-intelligence'): Course
    {
        $course = Course::create([
            'title' => $title,
            'slug' => $slug,
            'description' => "Complete curriculum for {$title}",
            'category' => 'AI & ML',
            'difficulty' => 'Beginner to Advanced',
            'duration' => '10 Weeks',
            'instructor' => 'Faculty Lead',
            'is_published' => true,
            'status' => 'published',
            'priority' => 1,
            'price' => 0,
            'average_rating' => 4.9,
            'learning_objectives' => ['Master AI Fundamentals', 'Build Deep Neural Networks'],
            'prerequisites' => ['Basic Math'],
            'skills_gained' => ['AI', 'Python', 'ML'],
        ]);

        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1 — AI Fundamentals',
            'slug' => 'module-1-ai-fundamentals-' . $course->id,
            'sort_order' => 1,
            'is_published' => true,
        ]);

        Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'What is Artificial Intelligence',
            'slug' => 'what-is-ai-' . $section->id,
            'type' => 'text',
            'duration' => '20 min',
            'sort_order' => 1,
            'is_published' => true,
            'description' => 'Introduction to artificial intelligence foundations.',
        ]);

        Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Intelligent Agents & Problem Solving',
            'slug' => 'intelligent-agents-' . $section->id,
            'type' => 'text',
            'duration' => '25 min',
            'sort_order' => 2,
            'is_published' => true,
            'description' => 'Agent environments and deterministic state search.',
        ]);

        return $course;
    }

    protected function assignEnrollment(User $student, Course $course): CourseEnrollment
    {
        return CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);
    }

    public function test_unauthenticated_user_cannot_enroll_or_view_lms_progress()
    {
        $course = $this->createTestCourse();

        $this->postJson("/api/courses/{$course->id}/enroll")
            ->assertStatus(401);

        $this->getJson("/api/courses/{$course->id}/lms-progress")
            ->assertStatus(401);
    }

    public function test_student_cannot_self_enroll_and_admin_duplicate_enrollment_is_prevented()
    {
        $student = User::factory()->create(['role' => 'student']);
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->createTestCourse();

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/enroll");

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Public self-enrollment is disabled. Student portal access is granted by MasterInTech administration after counselling and admission.',
            ]);

        $this->assertDatabaseMissing('course_enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/enrollments', [
                'user_id' => $student->id,
                'course_id' => $course->id,
                'status' => 'active',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/enrollments', [
                'user_id' => $student->id,
                'course_id' => $course->id,
            ])
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Student is already enrolled in this course.',
            ]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/enroll")
            ->assertStatus(403);
    }

    public function test_student_lms_progress_and_curriculum_hierarchy()
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createTestCourse();

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        // Fetch LMS progress
        $res = $this->actingAs($student, 'sanctum')
            ->getJson("/api/courses/{$course->id}/lms-progress");

        $res->assertStatus(200)
            ->assertJsonStructure([
                'course_id',
                'course_title',
                'progress_percentage',
                'completed_lessons',
                'completed_lesson_count',
                'total_lessons',
                'sections',
            ]);

        $data = $res->json();
        $this->assertEquals(0, $data['completed_lesson_count']);
        $this->assertEquals(2, $data['total_lessons']);
        $this->assertCount(1, $data['sections']);
    }

    public function test_lesson_completion_and_progress_calculation()
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createTestCourse();

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $lesson = Lesson::where('course_id', $course->id)->first();

        // 1. Mark lesson completed
        $compRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/lessons/{$lesson->id}/complete");

        $compRes->assertStatus(200)
            ->assertJson([
                'completed' => true,
                'lesson_id' => $lesson->id,
            ]);

        $this->assertDatabaseHas('lesson_progress', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'completed' => true,
        ]);

        // 2. Verify LMS progress recalculation (1 of 2 = 50%)
        $progRes = $this->actingAs($student, 'sanctum')
            ->getJson("/api/courses/{$course->id}/lms-progress");

        $progData = $progRes->json();
        $this->assertEquals(1, $progData['completed_lesson_count']);
        $this->assertEquals(50.0, (float) $progData['progress_percentage']);
        $this->assertContains($lesson->id, $progData['completed_lessons']);
    }

    public function test_course_completion_and_certificate_eligibility_flow()
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createTestCourse();

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        // 1. When not completed, certificate request fails with 422
        $earlyCertRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/certificate");
        $earlyCertRes->assertStatus(422);

        // 2. Mark all published lessons completed
        $publishedLessons = Lesson::where('course_id', $course->id)->where('is_published', true)->get();
        foreach ($publishedLessons as $les) {
            LessonProgress::updateOrCreate(
                [
                    'user_id' => $student->id,
                    'course_id' => $course->id,
                    'lesson_id' => $les->id,
                ],
                [
                    'section_id' => $les->section_id,
                    'status' => 'completed',
                    'completed' => true,
                    'completed_at' => now(),
                    'progress_percentage' => 100,
                ]
            );
        }

        // 3. Update progress & status
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/courses/{$course->id}/lms-progress")
            ->assertStatus(200)
            ->assertJson([
                'progress_percentage' => 100,
                'is_course_completed' => true,
            ]);

        // 4. Request certificate after 100% completion
        $certRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/certificate");

        $certRes->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'certificate' => [
                    'id',
                    'certificate_code',
                    'user_id',
                    'course_id',
                ],
            ]);

        $code = $certRes->json('certificate.certificate_code');
        $this->assertStringStartsWith('MIT-', $code);

        // 5. Verify certificate publicly
        $this->getJson("/api/verify-certificate/{$code}")
            ->assertStatus(200);
    }

    public function test_my_courses_endpoint_returns_accurate_dashboard_metrics()
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->createTestCourse();

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $myCoursesRes = $this->actingAs($student, 'sanctum')
            ->getJson('/api/my-courses');
        $myCoursesRes->assertStatus(200);

        $items = $myCoursesRes->json();
        $this->assertCount(1, $items);
        $this->assertEquals($course->id, $items[0]['course_id']);
        $this->assertEquals(2, $items[0]['total_lessons']);
        $this->assertEquals(0, $items[0]['completed_lessons']);
        $this->assertEquals(0, $items[0]['progress_percentage']);
    }
}
