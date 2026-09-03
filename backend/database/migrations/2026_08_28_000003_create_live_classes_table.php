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
        Schema::create('live_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('class_date');
            $table->string('start_time', 10);
            $table->string('end_time', 10)->nullable();
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->string('status', 20)->default('scheduled'); // scheduled, live, completed, cancelled

            // Video Platform Provider Architecture
            $table->string('provider', 30)->default('custom'); // zoom, teams, google_meet, jitsi, custom
            $table->string('meeting_id')->nullable();
            $table->text('meeting_url')->nullable();
            $table->text('host_url')->nullable();
            $table->string('passcode')->nullable();

            // Classroom Host Controls & Permission States
            $table->boolean('is_chat_enabled')->default(true);
            $table->boolean('is_mic_allowed_by_default')->default(false);
            $table->json('provider_metadata')->nullable();
            $table->json('settings')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_classes');
    }
};
