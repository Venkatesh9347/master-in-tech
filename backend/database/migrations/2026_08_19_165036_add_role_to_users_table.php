<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DB-001: This migration is intentionally duplicated by
     * 2026_08_19_170248_add_role_to_users_table_v2.php (same column, same
     * default). Both files are idempotent: each begins with a
     * Schema::hasColumn('users', 'role') guard and no-ops when the column
     * already exists. On a fresh database the first file adds the column and
     * the second no-ops; on an existing database the order no longer matters.
     *
     * DO NOT DELETE EITHER FILE: existing databases may already have recorded
     * both migration names in their migrations table. Removing one would break
     * `migrate:rollback` for those environments. The duplicate is safe by
     * design and is kept solely for migration-history compatibility.
     *
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'role')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement("ALTER TABLE users ADD COLUMN role VARCHAR(255) NOT NULL DEFAULT 'student'");

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('student')->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('ALTER TABLE users DROP COLUMN role');

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
