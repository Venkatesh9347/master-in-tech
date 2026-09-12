<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persistent provider webhook event ledger.
     *
     * Every verified webhook delivery is recorded exactly once per
     * (provider, provider_event_id). Redeliveries hit the unique index and
     * are acknowledged without reprocessing — the ledger, not memory, is
     * the idempotency source of truth.
     */
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->index();
            $table->string('provider_event_id', 255)->nullable();
            $table->string('event_type', 128)->index();
            $table->json('payload')->nullable();
            $table->string('status', 32)->default('received'); // received, processed, ignored, failed
            $table->foreignId('payment_transaction_id')->nullable()
                ->constrained('payment_transactions')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id'], 'webhook_events_provider_event_unique');
            $table->index(['provider', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
