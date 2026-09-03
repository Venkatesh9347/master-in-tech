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
        Schema::table('class_session_attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('class_session_attendances', 'duration_seconds')) {
                $table->integer('duration_seconds')->default(0)->after('left_at');
            }
            if (! Schema::hasColumn('class_session_attendances', 'reconnect_count')) {
                $table->integer('reconnect_count')->default(0)->after('duration_seconds');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_session_attendances', function (Blueprint $table) {
            if (Schema::hasColumn('class_session_attendances', 'reconnect_count')) {
                $table->dropColumn('reconnect_count');
            }
            if (Schema::hasColumn('class_session_attendances', 'duration_seconds')) {
                $table->dropColumn('duration_seconds');
            }
        });
    }
};
