<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_area_access_matrix_matches_spec(): void
    {
        $matrix = [
            'super_admin' => ['admin' => true, 'tutor' => true, 'crm' => true, 'company' => false, 'student' => false],
            'admin' => ['admin' => true, 'tutor' => true, 'crm' => true, 'company' => false, 'student' => false],
            'tutor' => ['admin' => false, 'tutor' => true, 'crm' => false, 'company' => false, 'student' => false],
            'faculty' => ['admin' => false, 'tutor' => true, 'crm' => false, 'company' => false, 'student' => false],
            'counsellor' => ['admin' => false, 'tutor' => false, 'crm' => true, 'company' => false, 'student' => false],
            'company' => ['admin' => false, 'tutor' => false, 'crm' => false, 'company' => true, 'student' => false],
            'recruiter' => ['admin' => false, 'tutor' => false, 'crm' => false, 'company' => true, 'student' => false],
            'student' => ['admin' => false, 'tutor' => false, 'crm' => false, 'company' => false, 'student' => true],
        ];

        foreach ($matrix as $role => $areas) {
            foreach ($areas as $area => $expected) {
                $this->assertSame(
                    $expected,
                    PermissionMatrix::canAccess($role, $area),
                    "role={$role} area={$area}"
                );
            }
        }
    }

    public function test_unknown_area_and_unknown_role_are_denied(): void
    {
        $this->assertFalse(PermissionMatrix::canAccess('admin', 'not_an_area'));
        $this->assertFalse(PermissionMatrix::canAccess('hacker', 'admin'));
        $this->assertFalse(PermissionMatrix::canAccess(null, 'admin'));
    }

    public function test_resolved_abilities_are_role_scoped(): void
    {
        $this->assertSame(
            array_fill_keys(array_keys(PermissionMatrix::TUTOR_ABILITIES), true),
            PermissionMatrix::abilitiesForRole('admin')
        );
        $this->assertSame(
            PermissionMatrix::TUTOR_ABILITIES,
            PermissionMatrix::abilitiesForRole('tutor')
        );
        $this->assertSame(
            PermissionMatrix::TUTOR_ABILITIES,
            PermissionMatrix::abilitiesForRole('faculty')
        );
        $this->assertSame(
            array_fill_keys(array_keys(PermissionMatrix::TUTOR_ABILITIES), false),
            PermissionMatrix::abilitiesForRole('student')
        );
        $this->assertSame(
            array_fill_keys(array_keys(PermissionMatrix::TUTOR_ABILITIES), false),
            PermissionMatrix::abilitiesForRole('counsellor')
        );
    }

    public function test_user_can_access_matches_matrix(): void
    {
        $user = User::factory()->create(['role' => 'faculty']);
        $this->assertTrue($user->canAccess('tutor'));
        $this->assertFalse($user->canAccess('admin'));
        $this->assertFalse($user->canAccess('crm'));

        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue($admin->canAccess('admin'));
        $this->assertTrue($admin->canAccess('tutor'));
        $this->assertTrue($admin->canAccess('crm'));
        $this->assertFalse($admin->canAccess('company'));
    }

    public function test_students_do_not_expose_tutor_abilities_in_api_payload(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $payload = $student->toArray();

        $this->assertArrayHasKey('permissions', $payload);
        $this->assertFalse($payload['permissions']['view_assigned_courses']);
        $this->assertFalse($payload['permissions']['view_students']);
        $this->assertFalse($payload['permissions']['create_quizzes']);
    }

    public function test_faculty_is_authorised_for_tutor_area(): void
    {
        $faculty = User::factory()->create(['role' => 'faculty']);

        $this->actingAs($faculty, 'sanctum')
            ->getJson('/api/tutor/stats')
            ->assertOk();
    }

    public function test_student_is_forbidden_from_tutor_area(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/tutor/stats')
            ->assertForbidden();
    }

    public function test_recruiter_is_forbidden_from_crm_and_admin_areas(): void
    {
        $recruiter = User::factory()->create(['role' => 'recruiter']);

        $this->actingAs($recruiter, 'sanctum')
            ->getJson('/api/admin/enquiries')
            ->assertForbidden();

        $this->actingAs($recruiter, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_admins_are_forbidden_from_corporate_partner_area(): void
    {
        foreach (['admin', 'super_admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/company/dashboard')
                ->assertForbidden();
        }
    }
}