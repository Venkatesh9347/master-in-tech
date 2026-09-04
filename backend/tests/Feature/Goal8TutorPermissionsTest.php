<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ClassMaterial;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Goal8TutorPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $tutorRakesh;
    private User $tutorPriya;
    private User $studentAlice;
    private User $studentBob;
    private Course $courseRakesh;
    private Course $coursePriya;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('materials');

        $this->admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@masterintech.test',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->tutorRakesh = User::factory()->create([
            'name' => 'Rakesh Faculty',
            'email' => 'rakesh@masterintech.test',
            'password' => Hash::make('password123'),
            'role' => 'tutor',
            'status' => 'active',
            'permissions' => [
                'view_assigned_courses' => true,
                'view_students' => true,
                'upload_materials' => true,
                'manage_materials' => true,
                'create_quizzes' => false,
                'edit_quizzes' => false,
                'delete_quizzes' => false,
                'publish_quizzes' => false,
                'view_quiz_results' => true,
            ],
        ]);

        $this->tutorPriya = User::factory()->create([
            'name' => 'Priya Faculty',
            'email' => 'priya@masterintech.test',
            'password' => Hash::make('password123'),
            'role' => 'tutor',
            'status' => 'active',
            'permissions' => [
                'view_assigned_courses' => true,
                'view_students' => true,
                'upload_materials' => true,
                'manage_materials' => true,
                'create_quizzes' => true,
                'edit_quizzes' => true,
                'delete_quizzes' => false,
                'publish_quizzes' => true,
                'view_quiz_results' => true,
            ],
        ]);

        $this->studentAlice = User::factory()->create([
            'name' => 'Alice Student',
            'email' => 'alice@masterintech.test',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->studentBob = User::factory()->create([
            'name' => 'Bob Student',
            'email' => 'bob@masterintech.test',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->courseRakesh = Course::create([
            'title' => 'Full Stack Web Development',
            'slug' => 'full-stack-web',
            'description' => 'MERN Stack course',
            'instructor' => $this->tutorRakesh->name,
            'instructor_id' => $this->tutorRakesh->id,
            'duration' => '12 weeks',
            'difficulty' => 'Intermediate',
            'price' => 199.00,
            'is_published' => true,
        ]);

        $this->coursePriya = Course::create([
            'title' => 'Cloud & DevOps Architecture',
            'slug' => 'cloud-devops-arch',
            'description' => 'Kubernetes & AWS course',
            'instructor' => $this->tutorPriya->name,
            'instructor_id' => $this->tutorPriya->id,
            'duration' => '8 weeks',
            'difficulty' => 'Advanced',
            'price' => 299.00,
            'is_published' => true,
        ]);

        // Alice is enrolled in Course Rakesh
        CourseEnrollment::create([
            'user_id' => $this->studentAlice->id,
            'course_id' => $this->courseRakesh->id,
            'enrolled_at' => now(),
            'status' => 'active',
            'progress_percentage' => 20,
        ]);

        // Bob is enrolled in Course Priya
        CourseEnrollment::create([
            'user_id' => $this->studentBob->id,
            'course_id' => $this->coursePriya->id,
            'enrolled_at' => now(),
            'status' => 'active',
            'progress_percentage' => 50,
        ]);
    }

    public function test_1_tutor_can_view_assigned_course(): void
    {
        $res = $this->actingAs($this->tutorRakesh, 'sanctum')->getJson("/api/tutor/courses/{$this->courseRakesh->id}");
        $res->assertStatus(200)
            ->assertJsonPath('id', $this->courseRakesh->id)
            ->assertJsonPath('title', 'Full Stack Web Development');
    }

    public function test_2_tutor_cannot_view_unrelated_course(): void
    {
        $res = $this->actingAs($this->tutorRakesh, 'sanctum')->getJson("/api/tutor/courses/{$this->coursePriya->id}");
        $res->assertStatus(403);
    }

    public function test_3_tutor_cannot_create_course(): void
    {
        $res = $this->actingAs($this->tutorRakesh, 'sanctum')->postJson('/api/tutor/courses', [
            'title' => 'Unauthorized New Course',
            'description' => 'Should be blocked',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
        ]);
        $res->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized. Only administrators can create courses.');
    }

    public function test_4_tutor_cannot_modify_course(): void
    {
        $res = $this->actingAs($this->tutorRakesh, 'sanctum')->putJson("/api/tutor/courses/{$this->courseRakesh->id}", [
            'title' => 'Hacked Course Title',
        ]);
        $res->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized. Tutors cannot modify courses. Courses are managed by administration.');
    }

    public function test_5_tutor_can_view_assigned_live_class(): void
    {
        $session = ClassSession::create([
            'course_id' => $this->courseRakesh->id,
            'tutor_id' => $this->tutorRakesh->id,
            'title' => 'React State Architecture',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '15:00',
            'end_time' => '16:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $res = $this->actingAs($this->tutorRakesh, 'sanctum')->getJson("/api/tutor/class-sessions/{$session->id}");
        $res->assertStatus(200)
            ->assertJsonPath('id', $session->id)
            ->assertJsonPath('title', 'React State Architecture');
    }

    public function test_6_tutor_cannot_modify_live_class_schedule(): void
    {
        $session = ClassSession::create([
            'course_id' => $this->courseRakesh->id,
            'tutor_id' => $this->tutorRakesh->id,
            'title' => 'Node.js Cluster Mode',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/999888777',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        // Tutor attempts to call admin update class endpoint
        $res = $this->actingAs($this->tutorRakesh, 'sanctum')->putJson("/api/admin/class-sessions/{$session->id}", [
            'title' => 'Tampered Schedule',
            'scheduled_date' => '2026-12-31',
        ]);
        $res->assertStatus(403);
    }

    public function test_7_tutor_can_upload_material_when_permitted(): void
    {
        $file = UploadedFile::fake()->create('react_cheatsheet.pdf', 1024, 'application/pdf');

        $res = $this->actingAs($this->tutorRakesh, 'sanctum')->postJson('/api/tutor/materials', [
            'course_id' => $this->courseRakesh->id,
            'title' => 'React 19 Cheatsheet',
            'description' => 'Summary of new hooks and server actions',
            'material_type' => 'pdf',
            'file' => $file,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('material.title', 'React 19 Cheatsheet')
            ->assertJsonPath('material.file_name', 'react_cheatsheet.pdf');

        $this->assertDatabaseHas('class_materials', [
            'course_id' => $this->courseRakesh->id,
            'uploaded_by' => $this->tutorRakesh->id,
            'title' => 'React 19 Cheatsheet',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'uploaded_material',
            'user_id' => $this->tutorRakesh->id,
        ]);
    }

    public function test_8_tutor_cannot_upload_material_to_unrelated_course(): void
    {
        $file = UploadedFile::fake()->create('unauthorized.pdf', 512, 'application/pdf');

        $res = $this->actingAs($this->tutorRakesh, 'sanctum')->postJson('/api/tutor/materials', [
            'course_id' => $this->coursePriya->id, // Not Rakesh's course
            'title' => 'Intruder Material',
            'file' => $file,
        ]);

        $res->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized. You cannot upload materials to a course not assigned to you.');
    }

    public function test_9_tutor_can_edit_own_material_when_permitted(): void
    {
        $material = ClassMaterial::create([
            'course_id' => $this->courseRakesh->id,
            'uploaded_by' => $this->tutorRakesh->id,
            'title' => 'Initial Notes',
            'description' => 'Draft description',
            'file_path' => '/storage/materials/notes.pdf',
            'file_name' => 'notes.pdf',
            'file_size' => 1024,
        ]);

        $res = $this->actingAs($this->tutorRakesh, 'sanctum')->putJson("/api/tutor/materials/{$material->id}", [
            'title' => 'Updated Comprehensive Notes',
            'description' => 'Updated detailed description',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('material.title', 'Updated Comprehensive Notes');

        $this->assertDatabaseHas('class_materials', [
            'id' => $material->id,
            'title' => 'Updated Comprehensive Notes',
        ]);
    }

    public function test_10_tutor_cannot_delete_another_tutor_material(): void
    {
        $materialPriya = ClassMaterial::create([
            'course_id' => $this->coursePriya->id,
            'uploaded_by' => $this->tutorPriya->id,
            'title' => 'Kubernetes Manifests',
            'file_path' => '/storage/materials/k8s.zip',
            'file_name' => 'k8s.zip',
            'file_size' => 2048,
        ]);

        // Rakesh attempts to delete Priya's material
        $res = $this->actingAs($this->tutorRakesh, 'sanctum')->deleteJson("/api/tutor/materials/{$materialPriya->id}");
        $res->assertStatus(403)
            ->assertJsonPath('message', "Unauthorized. You cannot delete another tutor's material.");
    }

    public function test_11_tutor_without_quiz_permission_cannot_create_quiz(): void
    {
        // Rakesh has create_quizzes = false
        $res = $this->actingAs($this->tutorRakesh, 'sanctum')->postJson('/api/tutor/quizzes', [
            'course_id' => $this->courseRakesh->id,
            'title' => 'JavaScript Basics Quiz',
            'passing_score' => 70,
        ]);

        $res->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized. You do not have permission to create quizzes.');
    }

    public function test_12_tutor_with_quiz_permission_can_create_quiz(): void
    {
        // Priya has create_quizzes = true
        $res = $this->actingAs($this->tutorPriya, 'sanctum')->postJson('/api/tutor/quizzes', [
            'course_id' => $this->coursePriya->id,
            'title' => 'Docker & Containers Quiz',
            'description' => 'Testing dockerfile syntax and volume mounts',
            'time_limit' => 20,
            'passing_score' => 80,
            'questions' => [
                [
                    'question' => 'What instruction exposes a container port?',
                    'type' => 'multiple_choice',
                    'marks' => 1,
                    'options' => [
                        ['option_text' => 'EXPOSE', 'is_correct' => true],
                        ['option_text' => 'PORT', 'is_correct' => false],
                    ],
                ],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('quiz.title', 'Docker & Containers Quiz')
            ->assertJsonPath('quiz.passing_score', 80);

        $this->assertDatabaseHas('quizzes', [
            'title' => 'Docker & Containers Quiz',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created_quiz',
            'user_id' => $this->tutorPriya->id,
        ]);
    }

    public function test_13_tutor_without_delete_permission_cannot_delete_quiz(): void
    {
        $section = Section::create(['course_id' => $this->coursePriya->id, 'title' => 'Section 1']);
        $lesson = Lesson::create(['course_id' => $this->coursePriya->id, 'section_id' => $section->id, 'title' => 'Lesson 1', 'slug' => 'l1', 'is_published' => true]);
        $quiz = Quiz::create([
            'lesson_id' => $lesson->id,
            'title' => 'Sample Quiz',
            'passing_score' => 70,
        ]);

        // Priya has delete_quizzes = false
        $res = $this->actingAs($this->tutorPriya, 'sanctum')->deleteJson("/api/tutor/quizzes/{$quiz->id}");
        $res->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized. You do not have permission to delete quizzes.');
    }

    public function test_14_student_can_access_authorized_material(): void
    {
        // Upload a real material so the private-storage download path is exercised.
        $file = UploadedFile::fake()->create('roadmap.pdf', 1024, 'application/pdf');
        $upload = $this->actingAs($this->tutorRakesh, 'sanctum')->postJson('/api/tutor/materials', [
            'course_id' => $this->courseRakesh->id,
            'title' => 'Fullstack RoadMap PDF',
            'material_type' => 'pdf',
            'file' => $file,
        ]);
        $upload->assertStatus(201);
        $materialId = $upload->json('material.id');

        // Stored file_path must be a private relative storage path, NOT a public URL.
        $stored = \App\Models\ClassMaterial::findOrFail($materialId);
        $this->assertStringStartsWith('materials/', $stored->file_path);
        $this->assertStringNotContainsString('/storage/', $stored->file_path);

        // Alice is enrolled in Course Rakesh and can download the actual bytes.
        $res = $this->actingAs($this->studentAlice, 'sanctum')->get("/api/materials/{$materialId}/download");
        $res->assertStatus(200);
        $res->assertDownload('roadmap.pdf');
    }

    public function test_15_student_cannot_access_unrelated_material(): void
    {
        $material = ClassMaterial::create([
            'course_id' => $this->coursePriya->id,
            'uploaded_by' => $this->tutorPriya->id,
            'title' => 'DevOps Secret Handbook',
            'file_path' => '/storage/materials/secret.pdf',
            'file_name' => 'secret.pdf',
            'file_size' => 1024,
        ]);

        // Alice is NOT enrolled in Priya's course
        $res = $this->actingAs($this->studentAlice, 'sanctum')->getJson("/api/materials/{$material->id}/download");
        $res->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized. You do not have access to this protected learning material.');
    }

    public function test_16_student_can_access_published_quiz(): void
    {
        $section = Section::create(['course_id' => $this->courseRakesh->id, 'title' => 'Frontend Basics']);
        $lesson = Lesson::create(['course_id' => $this->courseRakesh->id, 'section_id' => $section->id, 'title' => 'DOM Manipulation', 'slug' => 'dom-man', 'is_published' => true]);
        $quiz = Quiz::create([
            'lesson_id' => $lesson->id,
            'title' => 'DOM Manipulation Quiz',
            'passing_score' => 70,
            'is_published' => true,
        ]);

        $res = $this->actingAs($this->studentAlice, 'sanctum')->getJson("/api/quizzes/{$quiz->id}");
        $res->assertStatus(200)
            ->assertJsonPath('quiz.id', $quiz->id)
            ->assertJsonPath('quiz.title', 'DOM Manipulation Quiz');
    }

    public function test_17_unauthorized_user_cannot_access_protected_material(): void
    {
        $material = ClassMaterial::create([
            'course_id' => $this->courseRakesh->id,
            'uploaded_by' => $this->tutorRakesh->id,
            'title' => 'Protected Guide',
            'file_path' => '/storage/materials/guide.pdf',
            'file_name' => 'guide.pdf',
            'file_size' => 1024,
        ]);

        // Bob is NOT enrolled in Course Rakesh
        $res = $this->actingAs($this->studentBob, 'sanctum')->getJson("/api/materials/{$material->id}/download");
        $res->assertStatus(403);
    }

    public function test_18_admin_can_modify_tutor_permissions(): void
    {
        // Admin grants Rakesh quiz permissions
        $res = $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/tutors/{$this->tutorRakesh->id}/permissions", [
            'permissions' => [
                'create_quizzes' => true,
                'edit_quizzes' => true,
                'delete_quizzes' => true,
                'publish_quizzes' => true,
            ],
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('tutor.permissions.create_quizzes', true)
            ->assertJsonPath('tutor.permissions.delete_quizzes', true);

        $this->assertTrue($this->tutorRakesh->fresh()->hasPermission('create_quizzes'));
        $this->assertTrue($this->tutorRakesh->fresh()->hasPermission('delete_quizzes'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'updated_tutor_permissions',
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_19_admin_can_load_faculty_roster_with_permissions(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/tutors');

        $res->assertStatus(200)
            ->assertJsonIsArray()
            ->assertJsonCount(2);

        $res->assertJsonStructure([
            '*' => [
                'id',
                'name',
                'email',
                'role',
                'status',
                'avatar',
                'expertise',
                'taught_courses_count',
                'permissions' => [
                    'view_assigned_courses',
                    'view_students',
                    'upload_materials',
                    'manage_materials',
                    'create_quizzes',
                    'edit_quizzes',
                    'delete_quizzes',
                    'publish_quizzes',
                    'view_quiz_results',
                ],
            ],
        ]);
    }

    public function test_20_empty_faculty_roster_returns_empty_array(): void
    {
        // Delete all tutors
        User::whereIn('role', ['tutor', 'faculty', 'instructor'])->delete();

        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/tutors');

        $res->assertStatus(200)
            ->assertJsonIsArray()
            ->assertJsonCount(0);
    }

    public function test_21_unauthorized_user_cannot_access_faculty_roster(): void
    {
        // Guest
        $this->getJson('/api/admin/tutors')->assertStatus(401);

        // Student
        $this->actingAs($this->studentAlice, 'sanctum')
            ->getJson('/api/admin/tutors')
            ->assertStatus(403);

        // Tutor
        $this->actingAs($this->tutorRakesh, 'sanctum')
            ->getJson('/api/admin/tutors')
            ->assertStatus(403);
    }

    public function test_22_admin_can_load_single_tutor_permission_data(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/tutors/{$this->tutorRakesh->id}/permissions");

        $res->assertStatus(200)
            ->assertJsonPath('id', $this->tutorRakesh->id)
            ->assertJsonPath('name', $this->tutorRakesh->name)
            ->assertJsonPath('permissions.view_assigned_courses', true)
            ->assertJsonStructure([
                'id',
                'name',
                'email',
                'permissions',
                'default_permissions',
            ]);
    }

    public function test_23_single_active_session_enforced_on_faculty_roster(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'current_session_id' => 'session_device_A',
        ]);

        // Token bound to superseded session B
        $token = $admin->createToken('test_token', ['session:session_device_B'])->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/tutors');

        $res->assertStatus(401)
            ->assertJsonFragment([
                'code' => 'SESSION_REVOKED',
            ]);
    }
}
