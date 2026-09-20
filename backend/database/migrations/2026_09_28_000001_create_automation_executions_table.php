<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable idempotency guard for code-defined automation handlers.
     *
     * One row per (event, handler): concurrent or repeated evaluation of
     * the same bus event converges on a single execution. Pure schema
     * builder, portable across SQLite / MySQL / PostgreSQL.
     */
    public function up(): void
    {
        Schema::create('automation_executions', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64);
            $table->string('handler', 128);
            $table->string('entity_type', 64)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending, success, failed
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'handler'], 'automation_executions_event_handler_unique');
            $table->index('handler', 'automation_executions_handler_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_executions');
    }
};
