<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migration-history compatibility: the course_id migration must be safe to
 * run when the column already exists (legacy databases migrated under the
 * old `add_course_link_to_payment_transactions` filename carry an
 * equivalent column). Re-running up() must be a no-op, never a
 * duplicate-column failure.
 */
class MigrationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_id_migration_is_idempotent_when_column_exists(): void
    {
        // RefreshDatabase already migrated fresh, so the column exists —
        // exactly the legacy-database condition that previously failed.
        $this->assertTrue(Schema::hasColumn('payment_transactions', 'course_id'));

        $migration = require database_path(
            'migrations/2026_09_22_000001_add_course_id_to_payment_transactions_table.php'
        );

        // Must not throw a duplicate-column QueryException.
        $migration->up();

        $this->assertTrue(Schema::hasColumn('payment_transactions', 'course_id'));
        $this->assertTrue(Schema::hasIndex(
            'payment_transactions',
            'payment_transactions_course_id_index'
        ));
    }
}
