<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outbound webhook subscriptions + delivery ledger.
     *
     * Pure schema-builder, portable across SQLite / MySQL / PostgreSQL:
     * no raw SQL, no driver-specific types, short explicit index names.
     */
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('target_url', 2048);
            $table->string('secret', 255);
            $table->json('events')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active', 'webhook_subscriptions_active_index');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_subscription_id')
                ->constrained('webhook_subscriptions')
                ->cascadeOnDelete();
            $table->string('event', 128);
            $table->json('payload')->nullable();
            $table->string('status', 32)->default('pending'); // pending, delivered, failed, dead
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['webhook_subscription_id', 'status'], 'webhook_deliveries_sub_status_index');
            $table->index(['status', 'next_retry_at'], 'webhook_deliveries_retry_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
    }
};
