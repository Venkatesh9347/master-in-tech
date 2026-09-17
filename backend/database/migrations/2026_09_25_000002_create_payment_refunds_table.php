<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outbound refund ledger (P1-A).
     *
     * The payment_transactions row is a single-state machine (paid ->
     * refunded) and cannot represent partial refunds: flipping a paid row to
     * `refunded` after a ₹200 partial would erase the remaining refundable
     * amount. Each refund is therefore its own append-only row here; the
     * remaining refundable amount is derived as captured minus the sum of
     * `succeeded` rows.
     *
     * - (provider, provider_refund_id) is unique so a later inbound refund
     *   webhook for an already-recorded outbound refund reconciles instead of
     *   duplicating.
     * - (provider, idempotency_key) is unique so a retried logical admin
     *   request resolves to the original row instead of double-refunding.
     */
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_transaction_id')
                ->constrained('payment_transactions')
                ->cascadeOnDelete();
            $table->string('provider', 32)->index();
            $table->string('provider_refund_id', 120)->nullable()->index();
            $table->string('idempotency_key', 120)->index();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('amount_paise');
            $table->string('currency', 8)->default('INR');
            $table->string('status', 32)->default('succeeded'); // succeeded, failed
            $table->string('source', 16)->default('admin'); // admin, webhook
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_refund_id'], 'payment_refunds_provider_refund_unique');
            $table->unique(['provider', 'idempotency_key'], 'payment_refunds_idem_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
