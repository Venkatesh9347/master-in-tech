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
        // 1. Create Live Classroom Sessions table (Phase 1 Internal LiveKit Sessions)
        if (! Schema::hasTable('live_classroom_sessions')) {
            Schema::create('live_classroom_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('room_id')->unique(); // Internal unique room identifier (e.g. mit-room-<uuid>)
                $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
                $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
                $table->foreignId('tutor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('title');
                $table->text('description')->nullable();
                $table->date('scheduled_date');
                $table->string('start_time', 20);
                $table->string('end_time', 20)->nullable();
                $table->string('status')->default('scheduled'); // scheduled, live, completed, cancelled
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->json('settings')->nullable(); // Default mic/camera permissions, features
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('batch_id');
                $table->index('course_id');
                $table->index('tutor_id');
                $table->index('status');
                $table->index('scheduled_date');
            });
        }

        // 2. Create Live Classroom Participants table (Session membership & Phase 2+ extensible state)
        if (! Schema::hasTable('live_classroom_participants')) {
            Schema::create('live_classroom_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('live_classroom_session_id')->constrained('live_classroom_sessions')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role')->default('participant'); // host, co-host, participant
                $table->timestamp('joined_at')->nullable();
                $table->timestamp('left_at')->nullable();
                $table->integer('duration_seconds')->default(0);
                $table->boolean('is_mic_allowed')->default(false);
                $table->boolean('is_camera_allowed')->default(false);
                $table->boolean('is_hand_raised')->default(false);
                $table->timestamp('hand_raised_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(
                    ['live_classroom_session_id', 'user_id'],
                    'live_classroom_participants_session_user_index'
                );
                $table->index('role');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_classroom_participants');
        Schema::dropIfExists('live_classroom_sessions');
    }
};
