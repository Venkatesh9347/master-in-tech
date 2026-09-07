<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DB-002: Ensure class_sessions.tutor_id has a foreign key to users.id.
     *
     * The base migration (2026_09_04_000001_create_class_sessions_table)
     * already declares this FK for fresh installs. This corrective migration
     * exists only for databases created from an older schema where the FK is
     * missing — it installs the FK when absent and no-ops otherwise, so it is
     * safe to run on both fresh and existing environments.
     *
     * Nullability is intentionally left untouched: fresh databases already
     * define tutor_id NOT NULL, and existing environments may contain data we
     * must not silently alter. The FK simply enforces referential integrity
     * (which permits NULL tutor_id values on legacy rows).
     *
     * SQLite cannot add a foreign key via ALTER TABLE, and fresh SQLite
     * databases already get the FK from the base migration, so SQLite is
     * skipped.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if (! Schema::hasTable('class_sessions') || ! Schema::hasColumn('class_sessions', 'tutor_id')) {
            return;
        }

        // Preflight: refuse to install the FK if orphaned tutor_id rows exist.
        $orphans = DB::select(
            'SELECT COUNT(*) AS c FROM class_sessions cs LEFT JOIN users u ON u.id = cs.tutor_id WHERE cs.tutor_id IS NOT NULL AND u.id IS NULL'
        );

        if ((int) $orphans[0]->c > 0) {
            throw new \RuntimeException(
                'Cannot add foreign key class_sessions_tutor_id_foreign: '.$orphans[0]->c
                .' class_sessions row(s) reference a tutor_id that does not exist in users. '
                .'Resolve the orphaned rows before migrating.'
            );
        }

        if ($this->foreignKeyExists($driver)) {
            return;
        }

        DB::statement(
            'ALTER TABLE class_sessions ADD CONSTRAINT class_sessions_tutor_id_foreign '
            .'FOREIGN KEY (tutor_id) REFERENCES users(id) ON DELETE CASCADE'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['sqlite', 'mysql', 'mariadb'], true) && $this->foreignKeyExists($driver)) {
            DB::statement('ALTER TABLE class_sessions DROP FOREIGN KEY class_sessions_tutor_id_foreign');

            return;
        }

        if ($driver === 'pgsql' && $this->foreignKeyExists($driver)) {
            DB::statement('ALTER TABLE class_sessions DROP CONSTRAINT class_sessions_tutor_id_foreign');
        }
    }

    /**
     * Detect whether the class_sessions_tutor_id_foreign constraint exists on
     * the current connection (mysql/mariadb and pgsql only).
     */
    private function foreignKeyExists(string $driver): bool
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $rows = DB::select(
                "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'class_sessions'
                   AND CONSTRAINT_NAME = 'class_sessions_tutor_id_foreign'
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
            );

            return count($rows) > 0;
        }

        if ($driver === 'pgsql') {
            $rows = DB::select(
                "SELECT 1
                 FROM pg_constraint c
                 JOIN pg_class t ON t.oid = c.conrelid
                 WHERE c.conname = 'class_sessions_tutor_id_foreign'
                   AND t.relname = 'class_sessions'"
            );

            return count($rows) > 0;
        }

        return true;
    }
};