<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            if (! Schema::hasColumn('lesson_progress', 'started')) {
                $table->boolean('started')->default(false)->after('lesson_id');
            }
            if (! Schema::hasColumn('lesson_progress', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('started');
            }
            if (! Schema::hasColumn('lesson_progress', 'last_accessed_at')) {
                $table->timestamp('last_accessed_at')->nullable()->after('completed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            if (Schema::hasColumn('lesson_progress', 'started')) {
                $table->dropColumn('started');
            }
            if (Schema::hasColumn('lesson_progress', 'started_at')) {
                $table->dropColumn('started_at');
            }
            if (Schema::hasColumn('lesson_progress', 'last_accessed_at')) {
                $table->dropColumn('last_accessed_at');
            }
        });
    }
};
