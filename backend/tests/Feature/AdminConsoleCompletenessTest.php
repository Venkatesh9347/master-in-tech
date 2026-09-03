<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Enquiry;
use App\Models\EnquiryNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminConsoleCompletenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_kpis_and_operational_overview(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Root Admin']);
        $tutor = User::factory()->create(['role' => 'tutor', 'name' => 'Faculty Lead']);
        $student = User::factory()->create(['role' => 'student', 'name' => 'Active Student']);

        $course = Course::create([
            'title' => 'Advanced AI & Deep Learning',
            'slug' => 'adv-ai-deep-learning',
            'description' => 'Neural networks and generative models',
            'instructor' => $tutor->name,
            'instructor_id' => $tutor->id,
            'duration' => '12 weeks',
            'difficulty' => 'Advanced',
            'price' => 34999,
            'is_published' => true,
        ]);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'enrolled_at' => now(),
            'status' => 'active',
        ]);

        Enquiry::create([
            'name' => 'Candidate Lead',
            'email' => 'candidate@example.com',
            'phone' => '9988776655',
            'course_id' => $course->id,
            'course_title' => $course->title,
            'status' => 'new',
        ]);

        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/admin/dashboard');
        $res->assertStatus(200);

        $stats = $res->json('statistics');
        $this->assertEquals(1, $stats['total_students']);
        $this->assertEquals(1, $stats['total_tutors']);
        $this->assertEquals(1, $stats['total_admins']);
        $this->assertEquals(1, $stats['total_courses']);
        $this->assertEquals(1, $stats['published_courses']);
        $this->assertEquals(1, $stats['new_enquiries']);
        $this->assertEquals(1, $stats['active_enrollments']);

        $this->assertNotEmpty($res->json('admissions_pipeline'));
        $this->assertNotEmpty($res->json('courses_overview'));
        $this->assertNotEmpty($res->json('system_activity'));
    }

    public function test_admin_course_management_with_internal_pricing_privacy(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tutor = User::factory()->create(['role' => 'tutor']);
        $student = User::factory()->create(['role' => 'student']);

        Sanctum::actingAs($admin);

        // 1. Admin creates course with internal price
        $resCreate = $this->postJson('/api/courses', [
            'title' => 'Enterprise System Architecture',
            'description' => 'High throughput distributed systems',
            'category' => 'Cloud Computing',
            'instructor' => 'Lead Architect',
            'instructor_id' => $tutor->id,
            'duration' => '8 weeks',
            'difficulty' => 'Advanced',
            'price' => 49999,
            'is_published' => true,
        ]);

        $resCreate->assertStatus(201);
        $courseId = $resCreate->json('id');
        $this->assertEquals(49999, $resCreate->json('internal_price'));

        // 2. Admin edits course (toggle publish, modify duration)
        $resUpdate = $this->putJson("/api/courses/{$courseId}", [
            'title' => 'Enterprise Cloud Architecture Mastery',
            'is_published' => true,
            'duration' => '10 weeks',
            'price' => 54999,
        ]);
        $resUpdate->assertStatus(200);
        $this->assertEquals('Enterprise Cloud Architecture Mastery', $resUpdate->json('title'));
        $this->assertEquals(54999, $resUpdate->json('internal_price'));

        // 3. PUBLIC VISITOR: Price is NOT exposed
        Sanctum::actingAs(User::factory()->make()); // Unauthenticated public call
        $this->app['auth']->forgetGuards();
        $resPublic = $this->getJson("/api/courses/{$courseId}");
        $resPublic->assertStatus(200);
        $this->assertArrayNotHasKey('price', $resPublic->json());
        $this->assertArrayNotHasKey('internal_price', $resPublic->json());

        // 4. STUDENT: Price is NOT exposed
        Sanctum::actingAs($student);
        $resStudent = $this->getJson("/api/courses/{$courseId}");
        $resStudent->assertStatus(200);
        $this->assertArrayNotHasKey('price', $resStudent->json());
        $this->assertArrayNotHasKey('internal_price', $resStudent->json());

        // 5. TUTOR: Price is NOT exposed
        Sanctum::actingAs($tutor);
        $resTutor = $this->getJson("/api/courses/{$courseId}");
        $resTutor->assertStatus(200);
        $this->assertArrayNotHasKey('price', $resTutor->json());
        $this->assertArrayNotHasKey('internal_price', $resTutor->json());

        // 6. ADMIN: Price IS visible
        Sanctum::actingAs($admin);
        $resAdmin = $this->getJson("/api/courses/{$courseId}");
        $resAdmin->assertStatus(200);
        $this->assertEquals(54999, $resAdmin->json('internal_price'));
    }

    public function test_admin_user_and_role_management_operations(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        // 1. Admin provisions Tutor
        $resTutor = $this->postJson('/api/admin/users', [
            'name' => 'Dr. Robert AI',
            'email' => 'robert.ai@example.com',
            'password' => 'tutorPass123',
            'role' => 'tutor',
            'headline' => 'Principal AI Scientist',
            'expertise' => 'Deep Learning & PyTorch',
        ]);
        $resTutor->assertStatus(201);
        $tutorId = $resTutor->json('user.id');
        $this->assertEquals('tutor', $resTutor->json('user.role'));

        // 2. Admin inspects user profile
        $resShow = $this->getJson("/api/admin/users/{$tutorId}");
        $resShow->assertStatus(200)
            ->assertJsonFragment(['name' => 'Dr. Robert AI', 'role' => 'tutor']);

        // 3. Admin updates user profile
        $resUpdate = $this->putJson("/api/admin/users/{$tutorId}", [
            'name' => 'Dr. Robert AI PhD',
            'headline' => 'Head of Artificial Intelligence',
        ]);
        $resUpdate->assertStatus(200)
            ->assertJsonFragment(['name' => 'Dr. Robert AI PhD']);

        // 4. Admin updates user role
        $resRole = $this->putJson("/api/admin/users/{$tutorId}/role", [
            'role' => 'student',
        ]);
        $resRole->assertStatus(200)
            ->assertJsonFragment(['role' => 'student']);

        // 5. Admin deletes user safely
        $resDelete = $this->deleteJson("/api/admin/users/{$tutorId}");
        $resDelete->assertStatus(200);
        $this->assertNull(User::find($tutorId));
    }

    public function test_admin_security_and_role_isolation(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $tutor = User::factory()->create(['role' => 'tutor']);

        // Student blocked from Admin endpoints
        Sanctum::actingAs($student);
        $this->getJson('/api/admin/dashboard')->assertStatus(403);
        $this->getJson('/api/admin/users')->assertStatus(403);
        $this->getJson('/api/admin/enquiries')->assertStatus(403);
        $this->postJson('/api/admin/users', ['name' => 'Fake', 'email' => 'f@ex.com', 'password' => '123456', 'role' => 'tutor'])->assertStatus(403);
        $this->postJson('/api/courses', ['title' => 'Fake', 'description' => 'desc', 'instructor' => 'f', 'duration' => '1w', 'difficulty' => 'Beg'])->assertStatus(403);

        // Tutor blocked from Admin endpoints
        Sanctum::actingAs($tutor);
        $this->getJson('/api/admin/dashboard')->assertStatus(403);
        $this->getJson('/api/admin/users')->assertStatus(403);
        $this->getJson('/api/admin/enquiries')->assertStatus(403);
        $this->postJson('/api/admin/users', ['name' => 'Fake', 'email' => 'f@ex.com', 'password' => '123456', 'role' => 'tutor'])->assertStatus(403);

        // Guest blocked
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/dashboard')->assertStatus(401);
        $this->getJson('/api/admin/users')->assertStatus(401);
        $this->getJson('/api/admin/enquiries')->assertStatus(401);
    }
}
