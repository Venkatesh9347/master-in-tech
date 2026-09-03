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
        Schema::table('enquiries', function (Blueprint $table) {
            $table->dateTime('demo_date')->nullable()->after('preferred_time');
            $table->string('demo_time')->nullable()->after('demo_date');
            $table->string('demo_outcome')->nullable()->after('demo_time');
            $table->string('assigned_agent')->nullable()->after('demo_outcome');
            $table->foreignId('enrolled_user_id')->nullable()->after('assigned_agent')->constrained('users')->nullOnDelete();
            $table->dateTime('enrolled_at')->nullable()->after('enrolled_user_id');
        });

        Schema::create('enquiry_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_id')->constrained('enquiries')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->default('Admissions Team');
            $table->text('note');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enquiry_notes');

        Schema::table('enquiries', function (Blueprint $table) {
            $table->dropForeign(['enrolled_user_id']);
            $table->dropColumn([
                'demo_date',
                'demo_time',
                'demo_outcome',
                'assigned_agent',
                'enrolled_user_id',
                'enrolled_at',
            ]);
        });
    }
};
