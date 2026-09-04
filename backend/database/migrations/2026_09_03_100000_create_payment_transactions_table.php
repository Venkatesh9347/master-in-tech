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
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('razorpay');
            $table->string('order_id')->nullable()->index();
            $table->string('payment_id')->nullable()->index();
            $table->string('idempotency_key', 120)->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('amount_paise')->default(0);
            $table->string('currency', 8)->default('INR');
            $table->string('status', 40)->default('created');
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            // At-most-once: a retried order with the same idempotency key must
            // resolve to the original transaction, never create a duplicate.
            $table->unique(['provider', 'idempotency_key'], 'payment_transactions_idem_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
