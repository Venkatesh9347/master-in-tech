<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link a payment transaction to the purchased course and the enrollment
     * granted by the webhook. Nullable + nullOnDelete so payment audit rows
     * survive course/enrollment deletion and pre-chain rows stay valid.
     */
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_transactions', 'course_id')) {
                $table->foreignId('course_id')->nullable()->after('user_id')
                    ->constrained('courses')->nullOnDelete();
            }
            if (! Schema::hasColumn('payment_transactions', 'enrollment_id')) {
                $table->foreignId('enrollment_id')->nullable()->after('course_id')
                    ->constrained('course_enrollments')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('payment_transactions', 'enrollment_id')) {
                $table->dropConstrainedForeignId('enrollment_id');
            }
            if (Schema::hasColumn('payment_transactions', 'course_id')) {
                $table->dropConstrainedForeignId('course_id');
            }
        });
    }
};
