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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificateDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function completedCourseFor(User $student): Course
    {
        $course = Course::create([
            'title' => 'PDF Certificate Course',
            'slug' => 'pdf-certificate-course',
            'description' => 'Cert test course',
            'instructor' => 'Master Faculty',
            'price' => 4999,
            'duration' => '6 weeks',
            'difficulty' => 'Intermediate',
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

        return $course;
    }

    public function test_generate_creates_pdf_artifact_and_public_show_does_not_leak_email(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->completedCourseFor($student);

        $certRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/certificate");

        $certRes->assertCreated();
        $code = $certRes->json('certificate.certificate_code');
        $this->assertNotEmpty($code);

        // PDF artifact written to the (faked) private disk with a PDF header.
        Storage::disk('local')->assertExists('certificates/' . $code . '.pdf');
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get('certificates/' . $code . '.pdf'));

        // Public show must be a flattened, safe payload — no email, no user object.
        $show = $this->getJson("/api/certificates/{$code}");

        $show->assertOk();
        $show->assertJsonPath('certificate_code', $code);
        $show->assertJsonPath('recipient_name', $student->name);
        $show->assertJsonPath('course_title', $course->title);
        $show->assertJsonPath('has_pdf', true);
        $show->assertJsonMissingPath('user');
        $show->assertJsonMissing(['user' => ['email' => $student->email]]);
        $this->assertTrue(! str_contains($show->getContent(), $student->email));
    }

    public function test_download_pdf_as_owner_succeeds(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->completedCourseFor($student);

        $cert = Certificate::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'certificate_code' => 'MIT-2026-DOWNLOADOWNER01',
            'issued_at' => now(),
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->get("/api/student/certificates/{$cert->certificate_code}/download");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'inline; filename="MIT-2026-DOWNLOADOWNER01.pdf"');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // pdf_path recorded so the UI knows the artifact exists.
        $this->assertDatabaseHas('certificates', [
            'id' => $cert->id,
            'pdf_path' => 'certificates/MIT-2026-DOWNLOADOWNER01.pdf',
        ]);
    }

    public function test_download_pdf_by_another_student_is_forbidden(): void
    {
        $owner = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'student']);
        $course = $this->completedCourseFor($owner);

        $cert = Certificate::create([
            'user_id' => $owner->id,
            'course_id' => $course->id,
            'certificate_code' => 'MIT-2026-FORBIDDEN01',
            'issued_at' => now(),
        ]);

        $response = $this->actingAs($other, 'sanctum')
            ->getJson("/api/student/certificates/{$cert->certificate_code}/download");

        $response->assertStatus(403);
        Storage::disk('local')->assertMissing('certificates/MIT-2026-FORBIDDEN01.pdf');
    }

    public function test_download_pdf_by_admin_succeeds(): void
    {
        $owner = User::factory()->create(['role' => 'student']);
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->completedCourseFor($owner);

        $cert = Certificate::create([
            'user_id' => $owner->id,
            'course_id' => $course->id,
            'certificate_code' => 'MIT-2026-ADMINOK01',
            'issued_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get("/api/student/certificates/{$cert->certificate_code}/download");

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_download_pdf_requires_authentication(): void
    {
        $owner = User::factory()->create(['role' => 'student']);
        $course = $this->completedCourseFor($owner);

        $cert = Certificate::create([
            'user_id' => $owner->id,
            'course_id' => $course->id,
            'certificate_code' => 'MIT-2026-NOAUTH01',
            'issued_at' => now(),
        ]);

        $response = $this->getJson("/api/student/certificates/{$cert->certificate_code}/download");

        $response->assertStatus(401);
    }
}