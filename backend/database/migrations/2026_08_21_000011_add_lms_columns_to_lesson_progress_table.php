<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            if (! Schema::hasColumn('lesson_progress', 'section_id')) {
                $table->unsignedBigInteger('section_id')->nullable()->after('lesson_id');
            }
            if (! Schema::hasColumn('lesson_progress', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('completed');
            }
            if (! Schema::hasColumn('lesson_progress', 'progress_percentage')) {
                $table->decimal('progress_percentage', 5, 2)->default(0)->after('completed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            if (Schema::hasColumn('lesson_progress', 'section_id')) {
                $table->dropColumn('section_id');
            }
            if (Schema::hasColumn('lesson_progress', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
            if (Schema::hasColumn('lesson_progress', 'progress_percentage')) {
                $table->dropColumn('progress_percentage');
            }
        });
    }
};
