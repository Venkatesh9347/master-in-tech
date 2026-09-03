<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
            $table->string('duration')->nullable(); // e.g., "10 min"
            $table->enum('type', ['video', 'text', 'document', 'quiz', 'assignment'])
                ->default('text');
            $table->json('metadata')->nullable(); // type-specific data (video_url, content, etc.)
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
            $table->index(['course_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
