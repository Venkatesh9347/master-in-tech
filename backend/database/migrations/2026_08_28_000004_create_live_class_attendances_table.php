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
        Schema::create('live_class_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_class_id')->constrained('live_classes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('status', 20)->default('present'); // present, attended, left

            // Student Live State & Permissions
            $table->boolean('is_hand_raised')->default(false);
            $table->timestamp('hand_raised_at')->nullable();
            $table->boolean('is_mic_allowed')->default(false);
            $table->boolean('is_muted')->default(true);
            $table->boolean('is_removed')->default(false);

            $table->timestamps();

            // Unique index to prevent duplicate records per student session
            $table->index(['live_class_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_class_attendances');
    }
};
