<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('class_sessions')) {
            Schema::table('class_sessions', function (Blueprint $table) {
                if (! Schema::hasColumn('class_sessions', 'started_at')) {
                    $table->timestamp('started_at')->nullable()->after('scheduled_date');
                }
                if (! Schema::hasColumn('class_sessions', 'actual_duration_seconds')) {
                    $table->unsignedInteger('actual_duration_seconds')->nullable()->after('ended_at');
                }
                if (! Schema::hasColumn('class_sessions', 'host_user_id')) {
                    $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete()->after('tutor_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('class_sessions')) {
            Schema::table('class_sessions', function (Blueprint $table) {
                if (Schema::hasColumn('class_sessions', 'host_user_id')) {
                    $table->dropForeign(['host_user_id']);
                    $table->dropColumn('host_user_id');
                }
                if (Schema::hasColumn('class_sessions', 'actual_duration_seconds')) {
                    $table->dropColumn('actual_duration_seconds');
                }
                if (Schema::hasColumn('class_sessions', 'started_at')) {
                    $table->dropColumn('started_at');
                }
            });
        }
    }
};
