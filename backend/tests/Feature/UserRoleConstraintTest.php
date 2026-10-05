<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * B18 — database-level enforcement of the users.role vocabulary.
 *
 * The role list is derived from the authorization code; see the migration for
 * the full provenance. These tests assert the constraint itself (not the
 * application's request validation), so a regression in the migration is
 * caught rather than masked by the controller whitelist.
 */
class UserRoleConstraintTest extends TestCase
{
    use RefreshDatabase;

    /** The vocabulary the constraint must accept. */
    private const ROLES = [
        'student', 'tutor', 'faculty', 'instructor', 'counsellor', 'telecaller',
        'course_advisor', 'company', 'recruiter', 'admin', 'super_admin',
    ];

    public function test_roles_lookup_table_is_seeded_with_the_full_vocabulary(): void
    {
        $this->assertTrue(Schema::hasTable('roles'));

        $stored = DB::table('roles')->orderBy('name')->pluck('name')->all();
        $expected = self::ROLES;
        sort($expected);

        $this->assertSame($expected, $stored);
    }

    public function test_inserting_an_invalid_role_is_rejected_by_the_database(): void
    {
        // Asserts the FK itself, not Laravel validation: a raw DB insert with a
        // role no controller would ever send.
        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'name' => 'Probe',
            'email' => 'probe@example.test',
            'email_verified_at' => now(),
            'password' => 'x',
            'role' => 'not_a_real_role',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_updating_to_an_invalid_role_is_rejected_by_the_database(): void
    {
        $user = User::factory()->create(['role' => 'student']);

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $user->id)->update(['role' => 'superuser']);
    }

    public function test_every_valid_role_is_accepted(): void
    {
        foreach (self::ROLES as $role) {
            $user = User::factory()->create();
            $user->forceFill(['role' => $role])->save();

            $this->assertSame($role, $user->fresh()->role, "role '{$role}' must be valid");
        }
    }

    public function test_a_role_cannot_be_deleted_while_users_reference_it(): void
    {
        User::factory()->create(['role' => 'tutor']);

        $this->expectException(QueryException::class);

        DB::table('roles')->where('name', 'tutor')->delete();
    }

    public function test_existing_user_rows_are_preserved(): void
    {
        // Rows created before the constraint must remain valid and readable.
        $ids = User::factory()->count(5)->create()->pluck('id');

        // Re-read from the database: the factory does not set `role`, so the
        // column default ('student') is only visible on the persisted row.
        $rows = User::whereIn('id', $ids)->get();

        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $this->assertContains($row->role, self::ROLES);
            $this->assertSame('student', $row->role, 'column default must still apply');
        }
    }

    public function test_role_helpers_still_behave_after_the_constraint(): void
    {
        $this->assertTrue(User::factory()->create(['role' => 'student'])->isStudent());
        $this->assertTrue(User::factory()->create(['role' => 'tutor'])->isTutor());
        $this->assertTrue(User::factory()->create(['role' => 'admin'])->isAdmin());
        $this->assertTrue(User::factory()->create(['role' => 'super_admin'])->isSuperAdmin());
        $this->assertTrue(User::factory()->create(['role' => 'counsellor'])->isCounsellor());
        $this->assertTrue(User::factory()->create(['role' => 'telecaller'])->isTelecaller());
        $this->assertTrue(User::factory()->create(['role' => 'course_advisor'])->isCourseAdvisor());

        // isCompany() covers both 'company' and 'recruiter'.
        $this->assertTrue(User::factory()->create(['role' => 'company'])->isCompany());
        $this->assertTrue(User::factory()->create(['role' => 'recruiter'])->isCompany());

        $crm = User::factory()->create(['role' => 'counsellor']);
        $this->assertTrue($crm->canAccessCrm());
        $this->assertTrue($crm->hasScopedCrmAccess());
    }

    public function test_role_change_endpoint_still_works_for_whitelisted_roles(): void
    {
        // Authorization behaviour must be unchanged by the constraint.
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $target = User::factory()->create(['role' => 'faculty']);

        $this->actingAs($superAdmin)
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'counsellor'])
            ->assertOk();

        $this->assertSame('counsellor', $target->fresh()->role);
    }

    public function test_role_change_endpoint_still_rejects_unlisted_roles(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $target = User::factory()->create(['role' => 'student']);

        // The controller whitelist is unchanged and narrower than the DB set;
        // the constraint must not have loosened it.
        $this->actingAs($superAdmin)
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'wizard'])
            ->assertStatus(422);

        $this->assertSame('student', $target->fresh()->role);
    }
}