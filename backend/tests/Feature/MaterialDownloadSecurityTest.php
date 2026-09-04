<?php

namespace Tests\Feature;

use App\Models\ClassMaterial;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaterialDownloadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $tutor;
    private User $otherTutor;
    private User $activeStudent;
    private User $droppedStudent;
    private User $otherCourseStudent;
    private Course $course;
    private Course $otherCourse;
    private ClassMaterial $material;
    private ClassMaterial $otherMaterial;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('materials');
        Storage::fake('public');

        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->tutor = User::factory()->create([
            'role' => 'tutor',
            'status' => 'active',
            'permissions' => ['upload_materials' => true, 'manage_materials' => true],
        ]);
        $this->otherTutor = User::factory()->create(['role' => 'tutor', 'status' => 'active']);

        $this->activeStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->droppedStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->otherCourseStudent = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $this->course = Course::create([
            'title' => 'Material Security Course',
            'slug' => 'material-security-course',
            'description' => 'desc',
            'instructor' => $this->tutor->name,
            'instructor_id' => $this->tutor->id,
            'is_published' => true,
            'price' => 100,
            'duration' => '6 weeks',
            'difficulty' => 'Beginner',
        ]);

        $this->otherCourse = Course::create([
            'title' => 'Other Course',
            'slug' => 'other-course',
            'description' => 'desc',
            'instructor' => $this->otherTutor->name,
            'instructor_id' => $this->otherTutor->id,
            'is_published' => true,
            'price' => 100,
            'duration' => '6 weeks',
            'difficulty' => 'Beginner',
        ]);

        CourseEnrollment::create(['user_id' => $this->activeStudent->id, 'course_id' => $this->course->id, 'status' => 'active', 'enrolled_at' => now()]);
        CourseEnrollment::create(['user_id' => $this->droppedStudent->id, 'course_id' => $this->course->id, 'status' => 'dropped', 'enrolled_at' => now()]);
        CourseEnrollment::create(['user_id' => $this->otherCourseStudent->id, 'course_id' => $this->otherCourse->id, 'status' => 'active', 'enrolled_at' => now()]);

        $this->material = $this->createMaterial($this->course->id, $this->tutor->id, 'materials/security-notes.pdf', 'security-notes.pdf', 'application/pdf');
        $this->otherMaterial = $this->createMaterial($this->otherCourse->id, $this->otherTutor->id, 'materials/other.pdf', 'other.pdf', 'application/pdf');
    }

    private function createMaterial(int $courseId, int $uploadedBy, string $path, string $fileName, string $mime): ClassMaterial
    {
        Storage::disk('materials')->put($path, 'PRIVATE-MATERIAL-CONTENT');

        return ClassMaterial::create([
            'course_id' => $courseId,
            'uploaded_by' => $uploadedBy,
            'title' => $fileName,
            'file_path' => $path,
            'file_name' => $fileName,
            'file_type' => $mime,
            'file_size' => strlen('PRIVATE-MATERIAL-CONTENT'),
        ]);
    }

    public function test_unauthenticated_material_download_is_denied(): void
    {
        $this->getJson("/api/materials/{$this->material->id}/download")->assertStatus(401);
    }

    public function test_authorized_active_student_can_download_material_bytes(): void
    {
        $res = $this->actingAs($this->activeStudent, 'sanctum')
            ->get("/api/materials/{$this->material->id}/download");

        $res->assertStatus(200);
        $res->assertDownload('security-notes.pdf');
        $res->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_dropped_student_cannot_download_material(): void
    {
        $res = $this->actingAs($this->droppedStudent, 'sanctum')
            ->get("/api/materials/{$this->material->id}/download");

        $res->assertStatus(403);
    }

    public function test_unenrolled_student_cannot_download_material(): void
    {
        $res = $this->actingAs($this->otherCourseStudent, 'sanctum')
            ->get("/api/materials/{$this->material->id}/download");

        $res->assertStatus(403);
    }

    public function test_material_id_change_cannot_access_another_courses_material(): void
    {
        // Active student is enrolled only in $course; trying to reach the
        // material of another course (by its id) must be rejected (IDOR).
        $res = $this->actingAs($this->activeStudent, 'sanctum')
            ->get("/api/materials/{$this->otherMaterial->id}/download");

        $res->assertStatus(403);
    }

    public function test_tutor_can_download_material_for_their_own_course(): void
    {
        $res = $this->actingAs($this->tutor, 'sanctum')
            ->get("/api/materials/{$this->material->id}/download");

        $res->assertStatus(200);
        $res->assertDownload('security-notes.pdf');
    }

    public function test_tutor_cannot_download_material_of_unassigned_course(): void
    {
        $res = $this->actingAs($this->tutor, 'sanctum')
            ->get("/api/materials/{$this->otherMaterial->id}/download");

        $res->assertStatus(403);
    }

    public function test_admin_can_download_any_material(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/materials/{$this->otherMaterial->id}/download");

        $res->assertStatus(200);
        $res->assertDownload('other.pdf');
    }

    public function test_raw_storage_path_is_not_exposed_in_material_response(): void
    {
        $res = $this->actingAs($this->activeStudent, 'sanctum')
            ->get("/api/materials/{$this->material->id}/download");

        // The download must return the file bytes (a stream), not a JSON payload
        // containing the raw storage path or a public file_path URL.
        $res->assertStatus(200);
        $content = $res->streamedContent();
        $this->assertStringContainsString('PRIVATE-MATERIAL-CONTENT', $content);
        $this->assertStringNotContainsString('file_path', $content);
        $this->assertStringNotContainsString('storage/materials', $content);
    }
}
