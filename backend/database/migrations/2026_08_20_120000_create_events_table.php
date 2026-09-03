<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description');
            $table->text('short_description')->nullable();
            $table->string('banner')->nullable();
            $table->string('category')->default('Masterclass');
            $table->string('speaker_name');
            $table->string('speaker_designation');
            $table->string('speaker_image')->nullable();
            $table->dateTime('event_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->integer('duration'); // in minutes
            $table->enum('mode', ['online', 'offline'])->default('online');
            $table->string('meeting_url')->nullable();
            $table->string('location')->nullable();
            $table->decimal('price', 8, 2)->default(0.00);
            $table->integer('registration_limit')->nullable();
            $table->integer('registered_count')->default(0);
            $table->enum('status', ['draft', 'published', 'completed', 'cancelled'])->default('draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
