<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `placement_advisor` to the authoritative role vocabulary.
 *
 * Deliberately NOT an edit to 2026_10_04_000001_add_role_constraint_to_users_table.
 * That migration is already applied in every environment (local, and CI MySQL 8 /
 * PostgreSQL 16 jobs), so changing its ROLES constant would only affect fresh
 * installs and silently leave every existing database without the role. This
 * migration is therefore forward-only and works on both paths:
 *
 *   - Fresh install  : 000001 creates `roles`, this migration adds the row.
 *   - Existing deploy: 000001 already ran, this migration adds the row.
 *
 * The users.role foreign key is deliberately left untouched: the FK points at
 * roles.name, so inserting the new row is all that is required for the role to
 * become assignable, and no constraint needs rebuilding. `down()` removes only
 * the row, and refuses when any user still holds the role rather than orphaning
 * the FK.
 *
 * No user row is read, rewritten or deleted by this migration.
 */
return new class extends Migration
{
    private const ROLE = 'placement_advisor';

    private const LABEL = 'Placement Advisor';

    public function up(): void
    {
        // Defensive: on a database where 000001 has not run yet, create the
        // lookup table shape so this migration is safe to run standalone.
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function ($table) {
                $table->string('name', 32)->primary();
                $table->string('label', 64)->nullable();
                $table->timestamps();
                $table->index('label');
            });
        }

        // Idempotent insert. Plain INSERT ... would abort on a database where
        // the row is already present; updateOrInsert is a no-op in that case.
        $now = now();
        DB::table('roles')->updateOrInsert(
            ['name' => self::ROLE],
            ['label' => self::LABEL, 'created_at' => $now, 'updated_at' => $now],
        );

        // The role must actually be usable. Without this the FK would reject
        // every assignment, so failing loudly here is better than surfacing a
        // confusing constraint violation from an unrelated admin request.
        if (! DB::table('roles')->where('name', self::ROLE)->exists()) {
            throw new RuntimeException(
                'Failed to add '.self::ROLE.' to the roles lookup table.'
            );
        }
    }

    public function down(): void
    {
        $holders = DB::table('users')
            ->where('role', self::ROLE)
            ->count();

        if ($holders > 0) {
            throw new RuntimeException(
                'Cannot remove the '.self::ROLE.' role: '.$holders
                .' user(s) still hold it. Reassign them before rolling back.'
            );
        }

        DB::table('roles')->where('name', self::ROLE)->delete();
    }
};