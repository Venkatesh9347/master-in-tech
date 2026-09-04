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
class CertificateTest extends TestCase
{
    use RefreshDatabase;

    public function test_certificate_generation_upon_completion_and_public_verification(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $course = Course::create([
            'title' => 'Certified Cloud Architect',
            'slug' => 'certified-cloud-architect',
            'description' => 'Professional certification prep',
            'instructor' => 'Master Faculty',
            'price' => 9999,
            'duration' => '8 weeks',
            'difficulty' => 'Advanced',
        ]);

        $enrollment = CourseEnrollment::create([
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

        // 1. Generate certificate
        $certRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/certificate");

        $certRes->assertCreated();
        $code = $certRes->json('certificate.certificate_code');
        $this->assertNotEmpty($code);

        // 2. Public verification
        $verifyRes = $this->getJson("/api/verify-certificate/{$code}");
        $verifyRes->assertOk();
        $verifyRes->assertJsonPath('valid', true);
        $verifyRes->assertJsonPath('recipient_name', $student->name);
        $verifyRes->assertJsonPath('course_title', $course->title);
    }

    public function test_certificate_not_issued_for_course_without_lessons(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $course = Course::create([
            'title' => 'Empty Elective',
            'slug' => 'empty-elective',
            'description' => 'No content yet',
            'instructor' => 'Master Faculty',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
        ]);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'progress_percentage' => 100,
        ]);

        // A course with zero published lessons has nothing to complete - the
        // completion check must NOT be bypassed to mint a certificate.
        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/certificate");

        $res->assertStatus(422);

        $this->assertEquals(0, Certificate::where('user_id', $student->id)
            ->where('course_id', $course->id)
            ->count());
    }

    public function test_certificate_not_issued_until_all_lessons_completed(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $course = Course::create([
            'title' => 'Two Module Course',
            'slug' => 'two-module-course',
            'description' => 'Two lessons required',
            'instructor' => 'Master Faculty',
            'duration' => '6 weeks',
            'difficulty' => 'Intermediate',
        ]);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'progress_percentage' => 50,
        ]);

        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $lesson1 = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Lesson One',
            'type' => 'video',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $lesson2 = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Lesson Two',
            'type' => 'video',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        // Only one of two lessons completed -> no certificate yet.
        LessonProgress::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson1->id,
            'section_id' => $section->id,
            'completed' => true,
            'completed_at' => now(),
        ]);

        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/certificate");

        $res->assertStatus(422);
        $res->assertJsonPath('completed', 1);
        $res->assertJsonPath('total', 2);

        $this->assertEquals(0, Certificate::where('user_id', $student->id)
            ->where('course_id', $course->id)
            ->count());

        // Complete the second lesson -> certificate is now granted.
        LessonProgress::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson2->id,
            'section_id' => $section->id,
            'completed' => true,
            'completed_at' => now(),
        ]);

        $res2 = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/certificate");

        $res2->assertCreated();
        $this->assertEquals(1, Certificate::where('user_id', $student->id)
            ->where('course_id', $course->id)
            ->count());
    }

    public function test_certificate_code_is_unpredictable(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $course = Course::create([
            'title' => 'Predictability Check',
            'slug' => 'predictability-check',
            'description' => 'Cert code entropy',
            'instructor' => 'Master Faculty',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
        ]);

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
            'title' => 'Single Lesson',
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

        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/certificate");

        $res->assertCreated();
        $code = $res->json('certificate.certificate_code');

        // Format: MIT-YYYY-<12 uppercase alphanumeric chars>
        $this->assertMatchesRegularExpression('/^MIT-\d{4}-[A-Z0-9]{12}$/', $code);

        $randomPart = substr($code, strrpos($code, '-') + 1);

        // The unpredictable segment must be sufficiently long to resist brute-force.
        $this->assertGreaterThanOrEqual(12, strlen($randomPart));
    }
}
