<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B18 — enforce the users.role vocabulary at the database level.
 *
 * `role` was free-text varchar, so a typo or a crafted payload could persist a
 * role that no authorization rule recognises. This adds a lookup table seeded
 * with every role the codebase actually treats as legitimate, plus a foreign
 * key from users.role.
 *
 * Vocabulary derived from the authorization code (NOT assumed):
 *   - User model helpers      : student, tutor, admin, super_admin, counsellor,
 *                               telecaller, course_advisor, company, recruiter
 *   - EnsureUserIsTutorOrAdmin: tutor, faculty, admin, super_admin
 *   - AdminTutorPermission    : tutor, faculty, instructor
 *   - AdminUserController     : student, tutor, faculty, admin, super_admin,
 *                               counsellor, telecaller, course_advisor
 *   - CRM / Enquiry staffRoles: admin, super_admin, tutor, faculty, counsellor,
 *                               telecaller, course_advisor, company, recruiter
 *   - PlacementPortal         : admin, super_admin, recruiter
 *   - CourseController        : tutor, faculty, admin, super_admin
 *
 * Deliberately NOT included: 'user' and 'assistant' (ai_messages.role), and
 * 'host'/'participant' (classroom + live_classroom participant tables). Those
 * are separate columns on other tables.
 *
 * Portability: a lookup table + foreign key is the only approach that behaves
 * identically on SQLite (local/tests), MySQL 8 and PostgreSQL (production).
 * A bare CHECK constraint is not portable here because SQLite cannot
 * ALTER TABLE ... ADD CONSTRAINT without rebuilding the table, and this project
 * does not ship doctrine/dbal.
 *
 * Safety: existing rows are never rewritten. The migration aborts before
 * touching anything if any existing role is outside the vocabulary, so no user
 * is ever silently deleted or remapped.
 */
return new class extends Migration
{
    /**
     * The authoritative role vocabulary. Keep in sync with the authorization
     * lists referenced in the class docblock.
     */
    private const ROLES = [
        'student',
        'tutor',
        'faculty',
        'instructor',
        'counsellor',
        'telecaller',
        'course_advisor',
        'company',
        'recruiter',
        'admin',
        'super_admin',
    ];

    public function up(): void
    {
        // --- Pre-flight: never migrate over data we do not understand ---------
        $invalid = DB::table('users')
            ->whereNotNull('role')
            ->whereNotIn('role', self::ROLES)
            ->distinct()
            ->pluck('role')
            ->all();

        if ($invalid !== []) {
            throw new RuntimeException(
                'users.role contains value(s) outside the approved vocabulary: '
                . implode(', ', $invalid)
                . '. Resolve these rows manually before applying this migration; '
                . 'no user data was changed.'
            );
        }

        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                // String primary key rather than an auto-increment id so
                // users.role can reference the human-readable value directly.
                $table->string('name', 32)->primary();
                $table->string('label', 64)->nullable();
                $table->timestamps();

                $table->index('label');
            });
        }

        $now = now();
        foreach (self::ROLES as $role) {
            // Idempotent: safe if the table already existed with rows.
            DB::table('roles')->updateOrInsert(
                ['name' => $role],
                ['label' => ucwords(str_replace('_', ' ', $role)), 'updated_at' => $now, 'created_at' => $now],
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('role')->references('name')->on('roles')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role']);
        });

        Schema::dropIfExists('roles');
    }
};