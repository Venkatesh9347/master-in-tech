<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DB-003: Enforce at the database level that a (batch, student) pair can
     * have at most ONE active membership row.
     *
     * batch_students intentionally stores lifecycle history (active,
     * transferred, discontinued, completed, removed) so a student may have many
     * rows per batch. Only `status = 'active'` must be unique per pair.
     *
     * Compatibility (the database engines the project supports):
     *  - SQLite (dev/tests), MySQL/MariaDB and PostgreSQL (production) all
     *    support generated columns, so we use a generated column that is 1
     *    when status='active' and NULL otherwise plus a unique index over
     *    (batch_id, user_id, active_membership_key). NULL values never
     *    collide in a unique index, so any number of historical/non-active
     *    rows are allowed while only one active row can exist.
     *  - Partial/filtered unique indexes (SQLite/PG but NOT MySQL/MariaDB)
     *    were avoided because they are not available everywhere the project
     *    is deployed.
     *  - SQL Server (undefined SQL Server driver in config) is not a targeted
     *    deployment engine and does not support this exact DDL; the migration
     *    skips it and relies on the existing application-level guard.
     *
     * Safety: the migration refuses to run if duplicate active rows already
     * exist, so it fails loudly instead of silently dropping data.
     */
    public function up(): void
    {
        if (! Schema::hasTable('batch_students') || ! Schema::hasColumn('batch_students', 'status')) {
            return;
        }

        $duplicates = DB::select(
            'SELECT batch_id, user_id FROM batch_students WHERE status = :status GROUP BY batch_id, user_id HAVING COUNT(*) > 1',
            ['status' => 'active']
        );

        if (! empty($duplicates)) {
            throw new \RuntimeException(
                'batch_students contains duplicate ACTIVE memberships: batch_id='.$duplicates[0]->batch_id
                .', user_id='.$duplicates[0]->user_id.'. Deactivate or deduplicate these rows before '
                .'migrating so the single-active-membership constraint can be installed.'
            );
        }

        $driver = DB::getDriverName();

        if (! Schema::hasColumn('batch_students', 'active_membership_key')) {
            if (in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
                DB::statement($this->generatedColumnSql($driver));
            } else {
                // Unsupported engine (e.g. sqlsrv): this branch is not reached
                // for the project's supported engines; skip rather than fail.
                info('batch_students active-membership constraint skipped for unsupported driver ['.$driver.'].');
            }
        }

        if (! Schema::hasIndex('batch_students', 'batch_students_single_active_membership_unique')) {
            DB::statement(
                'CREATE UNIQUE INDEX batch_students_single_active_membership_unique '
                .'ON batch_students (batch_id, user_id, active_membership_key)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('batch_students')) {
            return;
        }

        if (Schema::hasIndex('batch_students', 'batch_students_single_active_membership_unique')) {
            $driver = DB::getDriverName();

            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('DROP INDEX batch_students_single_active_membership_unique ON batch_students');
            } elseif ($driver === 'pgsql' || $driver === 'sqlite') {
                DB::statement('DROP INDEX IF EXISTS batch_students_single_active_membership_unique');
            }
        }

        if (Schema::hasColumn('batch_students', 'active_membership_key')) {
            DB::statement('ALTER TABLE batch_students DROP COLUMN active_membership_key');
        }
    }

    private function generatedColumnSql(string $driver): string
    {
        return match ($driver) {
            'sqlite' => "ALTER TABLE batch_students ADD COLUMN active_membership_key AS (CASE WHEN status = 'active' THEN 1 ELSE NULL END)",
            'mysql', 'mariadb' => "ALTER TABLE batch_students ADD COLUMN active_membership_key TINYINT AS (IF(status = 'active', 1, NULL))",
            'pgsql' => "ALTER TABLE batch_students ADD COLUMN active_membership_key INTEGER GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN 1 ELSE NULL END) STORED",
            default => throw new \RuntimeException('Unsupported database driver ['.$driver.'] for batch_students constraint.'),
        };
    }
};