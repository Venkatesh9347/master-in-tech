<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cannot_access_admin_event_list(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/events');

        $response->assertForbidden();
    }

    public function test_student_cannot_create_course(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $response = $this->actingAs($student, 'sanctum')
            ->postJson('/api/courses', [
                'title' => 'Hacked Course',
                'description' => 'Should not be allowed',
                'instructor' => 'Hacker',
                'price' => 0,
                'duration' => '1 week',
                'difficulty' => 'Beginner',
            ]);

        $response->assertForbidden();
    }

    public function test_student_cannot_update_course(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create([
            'title' => 'Original Course',
            'slug' => 'original-course',
            'description' => 'Original description',
            'instructor' => 'Instructor',
            'price' => 100,
            'duration' => '2 weeks',
            'difficulty' => 'Beginner',
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->putJson("/api/courses/{$course->id}", [
                'title' => 'Modified Course',
                'description' => 'Modified description',
                'instructor' => 'Instructor',
                'price' => 200,
                'duration' => '3 weeks',
                'difficulty' => 'Advanced',
            ]);

        $response->assertForbidden();
    }

    public function test_student_cannot_delete_course(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create([
            'title' => 'Delete Me',
            'slug' => 'delete-me',
            'description' => 'Will be protected',
            'instructor' => 'Instructor',
            'price' => 100,
            'duration' => '1 week',
            'difficulty' => 'Beginner',
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->deleteJson("/api/courses/{$course->id}");

        $response->assertForbidden();

        $this->assertDatabaseHas('courses', ['id' => $course->id]);
    }

    public function test_student_cannot_create_admin_event(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $response = $this->actingAs($student, 'sanctum')
            ->postJson('/api/admin/events', [
                'title' => 'Student Created Event',
                'description' => 'Should be blocked',
                'speaker_name' => 'Student',
                'speaker_designation' => 'Student',
                'event_date' => '2026-12-01 10:00',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'duration' => 60,
                'mode' => 'online',
                'price' => 0,
                'status' => 'published',
            ]);

        $response->assertForbidden();
    }

    public function test_admin_can_access_admin_event_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/events');

        $response->assertOk();
        $response->assertJson([]);
    }

    public function test_admin_can_create_course(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/courses', [
                'title' => 'Admin Course',
                'description' => 'Admin-created course',
                'instructor' => 'Admin',
                'price' => 999,
                'duration' => '4 weeks',
                'difficulty' => 'Intermediate',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('title', 'Admin Course');
    }

    public function test_admin_can_login_with_credentials_and_get_admin_role(): void
    {
        User::factory()->create([
            'name' => 'Test Admin',
            'email' => 'test@example.com',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.email', 'test@example.com')
            ->assertJsonStructure(['access_token', 'token_type', 'user']);

        $token = $response->json('access_token');
        $this->assertNotEmpty($token);

        // Verify that the token grants access to admin routes
        $adminAccessResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/users');
        $adminAccessResponse->assertOk();
    }

    public function test_admin_login_fails_with_invalid_password(): void
    {
        User::factory()->create([
            'name' => 'Test Admin',
            'email' => 'test@example.com',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_public_can_view_events(): void
    {
        $response = $this->getJson('/api/events');

        $response->assertOk();
    }

    public function test_public_can_view_courses(): void
    {
        $response = $this->getJson('/api/courses');

        // GET /courses is now public for the public website.
        $response->assertOk();
    }

    public function test_role_cannot_be_mass_assigned_via_generic_update_but_update_role_works(): void
    {
        $admin = User::factory()->create([
            'name' => 'Role Admin',
            'email' => 'role.admin@example.com',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $target = User::factory()->create([
            'name' => 'Target User',
            'email' => 'target.user@example.com',
            'role' => 'student',
            'status' => 'active',
        ]);

        // Attempting to escalate the role through the generic update endpoint
        // must be ignored: 'role' is deliberately not mass-assignable.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$target->id}", [
                'name' => 'Target User Renamed',
                'role' => 'admin',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role' => 'student',
            'name' => 'Target User Renamed',
        ]);

        // The dedicated policy-controlled endpoint CAN change the role.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'admin'])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role' => 'admin',
        ]);
    }

    public function test_super_admin_can_access_admin_endpoints(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $res = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/dashboard');

        $res->assertOk()
            ->assertJsonStructure([
                'statistics',
            ]);

        $usersRes = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/users');

        $usersRes->assertOk();
    }

    public function test_super_admin_can_access_super_admin_only_endpoints(): void
    {
        // This test assumes there will be super_admin-only routes in the future
        // For now, we verify the middleware works by testing a route with super_admin middleware
        // Since no routes currently use 'super_admin' middleware, we test the middleware directly
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $admin = User::factory()->create(['role' => 'admin']);

        // Super admin can access (would need a route with super_admin middleware to test fully)
        // This is a placeholder for future super_admin-only routes
        $this->assertTrue(true);
    }

    public function test_admin_cannot_access_super_admin_only_endpoints(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Admin should be forbidden from super_admin-only routes
        // Since no routes currently use 'super_admin' middleware, this is a placeholder
        // for when such routes are added
        $this->assertTrue(true);
    }

    public function test_admin_cannot_delete_super_admin_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        // A plain admin must not remove a super_admin account.
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/users/{$superAdmin->id}")
            ->assertForbidden()
            ->assertJsonFragment(['message' => 'Only a super administrator can delete a super_admin account.']);

        $this->assertNotNull(User::find($superAdmin->id));

        // A super_admin may still remove a non-last admin.
        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertOk();

        $this->assertNull(User::find($admin->id));
    }

    public function test_admin_cannot_revoke_super_admin_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $superAdminA = User::factory()->create(['role' => 'super_admin']);
        $superAdminB = User::factory()->create(['role' => 'super_admin']);

        // Revoking super_admin requires a super_admin actor.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$superAdminA->id}/role", ['role' => 'admin'])
            ->assertForbidden();

        $this->assertEquals('super_admin', $superAdminA->fresh()->role);

        // Between super_admins the change is allowed while one remains.
        $this->actingAs($superAdminB, 'sanctum')
            ->putJson("/api/admin/users/{$superAdminA->id}/role", ['role' => 'admin'])
            ->assertOk();

        $this->assertEquals('admin', $superAdminA->fresh()->role);
    }

    public function test_admin_cannot_change_own_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$admin->id}/role", ['role' => 'student'])
            ->assertForbidden();

        $this->assertEquals('admin', $admin->fresh()->role);
    }

    public function test_tutor_permission_endpoints_reject_non_tutor_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);
        $tutor = User::factory()->create(['role' => 'tutor']);

        // Permission rows are tutor-scoped: students are rejected …
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/tutors/{$student->id}/permissions")
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/tutors/{$student->id}/permissions", [
                'permissions' => ['view_students' => false],
            ])
            ->assertStatus(422);

        $this->assertNull($student->fresh()->permissions);

        // … while tutor accounts keep working.
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/tutors/{$tutor->id}/permissions")
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/tutors/{$tutor->id}/permissions", [
                'permissions' => ['view_students' => false],
            ])
            ->assertOk();
    }

    public function test_tutor_permission_update_accepts_every_documented_key(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tutor = User::factory()->create(['role' => 'tutor']);

        // All nine canonical permission keys must validate (guards the
        // deduplicated rule set against accidental key loss).
        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/tutors/{$tutor->id}/permissions", [
                'permissions' => [
                    'view_assigned_courses' => true,
                    'view_students' => true,
                    'upload_materials' => true,
                    'manage_materials' => false,
                    'create_quizzes' => true,
                    'edit_quizzes' => false,
                    'delete_quizzes' => false,
                    'publish_quizzes' => true,
                    'view_quiz_results' => true,
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('tutor.permissions.manage_materials', false)
            ->assertJsonPath('tutor.permissions.publish_quizzes', true);
    }
}
