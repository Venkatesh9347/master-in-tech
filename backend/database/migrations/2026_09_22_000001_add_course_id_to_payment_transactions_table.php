<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link payment transactions to the course being purchased so a verified
     * paid event can activate the student's enrollment exactly once.
     *
     * Compatibility: databases migrated under the legacy
     * `add_course_link_to_payment_transactions` migration already carry an
     * equivalent nullable course_id (FK to courses, SET NULL on delete). The
     * column is therefore added only when absent; when present, only the
     * missing standard index is backfilled. Fresh databases are unaffected.
     */
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_transactions', 'course_id')) {
                $table->foreignId('course_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('courses')
                    ->nullOnDelete()
                    ->index();
            } elseif (! Schema::hasIndex('payment_transactions', 'payment_transactions_course_id_index')) {
                $table->index('course_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_id');
        });
    }
};