<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P1-C certificate revocation (minimal explicit state).
     *
     * The certificates table has no status/revocation representation, so a
     * revoked certificate cannot be distinguished from a valid one. These
     * four columns are the minimum required for an append-only
     * active -> revoked transition with server-derived attribution.
     * Existing rows default to `active`; no data is altered.
     */
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->after('pdf_path');
            $table->timestamp('revoked_at')->nullable()->after('status');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')
                ->constrained('users')->nullOnDelete();
            $table->string('revocation_reason', 500)->nullable()->after('revoked_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['status', 'revoked_at', 'revocation_reason']);
        });
    }
};
