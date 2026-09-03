<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Enhance lesson_progress with status, playback tracking, and explicit indexes
        Schema::table('lesson_progress', function (Blueprint $table) {
            if (! Schema::hasColumn('lesson_progress', 'status')) {
                $table->string('status', 30)->default('not_started')->after('section_id');
            }
            if (! Schema::hasColumn('lesson_progress', 'current_playback_seconds')) {
                $table->decimal('current_playback_seconds', 8, 2)->default(0)->after('progress_percentage');
            }
            if (! Schema::hasColumn('lesson_progress', 'duration_seconds')) {
                $table->decimal('duration_seconds', 8, 2)->nullable()->after('current_playback_seconds');
            }
            if (! Schema::hasColumn('lesson_progress', 'last_playback_position')) {
                $table->decimal('last_playback_position', 8, 2)->default(0)->after('duration_seconds');
            }
        });

        // 2. Create learning_activity_logs audit table
        if (! Schema::hasTable('learning_activity_logs')) {
            Schema::create('learning_activity_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('course_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('lesson_id')->nullable()->index();
                $table->string('event_type', 50)->index();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'course_id']);
                $table->index(['event_type', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_activity_logs');

        Schema::table('lesson_progress', function (Blueprint $table) {
            if (Schema::hasColumn('lesson_progress', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('lesson_progress', 'current_playback_seconds')) {
                $table->dropColumn('current_playback_seconds');
            }
            if (Schema::hasColumn('lesson_progress', 'duration_seconds')) {
                $table->dropColumn('duration_seconds');
            }
            if (Schema::hasColumn('lesson_progress', 'last_playback_position')) {
                $table->dropColumn('last_playback_position');
            }
        });
    }
};
