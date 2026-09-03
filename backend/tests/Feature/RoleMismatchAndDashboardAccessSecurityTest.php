<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleMismatchAndDashboardAccessSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $admin;
    private User $faculty;
    private User $tutor;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'name' => 'Test Admin',
            'email' => 'admin@masterintech.com',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->admin = User::factory()->create([
            'name' => 'Operations Admin',
            'email' => 'ops@masterintech.com',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->faculty = User::factory()->create([
            'name' => 'Professor Sharma',
            'email' => 'faculty@masterintech.com',
            'role' => 'faculty',
            'status' => 'active',
        ]);

        $this->tutor = User::factory()->create([
            'name' => 'Instructor Rakesh',
            'email' => 'tutor@masterintech.com',
            'role' => 'tutor',
            'status' => 'active',
        ]);

        $this->student = User::factory()->create([
            'name' => 'Alice Student',
            'email' => 'alice@student.com',
            'role' => 'student',
            'status' => 'active',
        ]);
    }

    public function test_super_admin_can_access_admin_endpoints(): void
    {
        $res = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/admin/dashboard');

        $res->assertOk()
            ->assertJsonStructure([
                'statistics',
            ]);

        $usersRes = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/admin/users');

        $usersRes->assertOk();
    }

    public function test_admin_can_access_admin_endpoints(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard');

        $res->assertOk();
    }

    public function test_faculty_and_tutor_cannot_access_admin_endpoints(): void
    {
        $resFaculty = $this->actingAs($this->faculty, 'sanctum')
            ->getJson('/api/admin/dashboard');

        $resFaculty->assertForbidden()
            ->assertJson(['message' => 'Unauthorized. Admin access required.']);

        $resTutor = $this->actingAs($this->tutor, 'sanctum')
            ->getJson('/api/admin/users');

        $resTutor->assertForbidden()
            ->assertJson(['message' => 'Unauthorized. Admin access required.']);
    }

    public function test_student_cannot_access_admin_endpoints(): void
    {
        $res = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/admin/dashboard');

        $res->assertForbidden()
            ->assertJson(['message' => 'Unauthorized. Admin access required.']);
    }

    public function test_faculty_and_tutor_can_access_tutor_endpoints(): void
    {
        $resTutor = $this->actingAs($this->tutor, 'sanctum')
            ->getJson('/api/tutor/stats');

        $resTutor->assertOk();

        $resFaculty = $this->actingAs($this->faculty, 'sanctum')
            ->getJson('/api/tutor/stats');

        $resFaculty->assertOk();
    }

    public function test_student_cannot_access_tutor_endpoints(): void
    {
        $res = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/tutor/stats');

        $res->assertForbidden()
            ->assertJson(['message' => 'Unauthorized. Tutor or Admin access required.']);
    }

    public function test_api_user_returns_real_backend_role_and_identity(): void
    {
        // Super Admin
        $resSuperAdmin = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/user');

        $resSuperAdmin->assertOk()
            ->assertJson([
                'id' => $this->superAdmin->id,
                'name' => 'Test Admin',
                'email' => 'admin@masterintech.com',
                'role' => 'super_admin',
            ]);

        // Faculty
        $resFaculty = $this->actingAs($this->faculty, 'sanctum')
            ->getJson('/api/user');

        $resFaculty->assertOk()
            ->assertJson([
                'id' => $this->faculty->id,
                'name' => 'Professor Sharma',
                'role' => 'faculty',
            ]);

        // Tutor
        $resTutor = $this->actingAs($this->tutor, 'sanctum')
            ->getJson('/api/user');

        $resTutor->assertOk()
            ->assertJson([
                'id' => $this->tutor->id,
                'name' => 'Instructor Rakesh',
                'role' => 'tutor',
            ]);

        // Student
        $resStudent = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/user');

        $resStudent->assertOk()
            ->assertJson([
                'id' => $this->student->id,
                'name' => 'Alice Student',
                'role' => 'student',
            ]);
    }
}
