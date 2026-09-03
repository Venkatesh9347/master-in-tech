<?php

namespace Tests\Feature;

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
}
