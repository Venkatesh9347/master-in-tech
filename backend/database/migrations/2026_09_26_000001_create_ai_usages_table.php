<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->string('model', 100)->nullable();
            $table->string('operation', 50)->default('generate');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('request_id', 100)->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->decimal('estimated_cost', 10, 6)->nullable();
            $table->string('currency', 10)->default('USD');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->boolean('success')->default(true);
            $table->string('error_code', 100)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['provider', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usages');
    }
};
