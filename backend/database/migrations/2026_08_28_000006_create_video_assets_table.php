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
        Schema::create('video_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->string('title');
            $table->string('driver', 50)->default('local_hls'); // 'local_hls', 'mux', 'cloudflare', 's3'
            $table->string('asset_id')->unique(); // Internal asset UUID or external Mux asset ID
            $table->string('playback_id')->nullable(); // Public playback identifier
            $table->string('status', 30)->default('ready'); // 'processing', 'ready', 'error'
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->json('resolutions')->nullable(); // e.g. ["360p", "480p", "720p", "1080p"]
            $table->string('encryption_key_hash', 64)->nullable(); // SHA-256 hash of AES-128 key
            $table->string('storage_path', 500)->nullable(); // Private disk storage path
            $table->json('metadata')->nullable(); // Provider metadata, aspect ratio, audio tracks
            $table->timestamps();

            $table->index(['course_id', 'lesson_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_assets');
    }
};
