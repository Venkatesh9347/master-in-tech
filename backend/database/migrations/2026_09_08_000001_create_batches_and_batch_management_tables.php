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
        // 1. Add course code column if missing
        Schema::table('courses', function (Blueprint $table) {
            if (! Schema::hasColumn('courses', 'code')) {
                $table->string('code')->nullable()->after('slug');
            }
        });

        // 2. Create Batches table
        if (! Schema::hasTable('batches')) {
            Schema::create('batches', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('code')->unique();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->foreignId('tutor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->string('status')->default('upcoming'); // upcoming, ongoing, completed, cancelled
                $table->string('schedule_type')->default('weekdays'); // weekdays, weekends, daily, custom
                $table->string('schedule_time')->nullable(); // e.g. "09:00 AM - 11:00 AM"
                $table->integer('max_students')->nullable();
                $table->string('meeting_link')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();

                $table->index('course_id');
                $table->index('status');
                $table->index('start_date');
            });
        }

        // 3. Create Batch Students Pivot Table (Membership with state history)
        if (! Schema::hasTable('batch_students')) {
            Schema::create('batch_students', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('status')->default('active'); // active, transferred, discontinued, completed
                $table->timestamp('joined_at')->nullable();
                $table->timestamp('left_at')->nullable();
                $table->timestamp('discontinued_at')->nullable();
                $table->text('discontinuation_reason')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['batch_id', 'user_id']);
                $table->index('status');
            });
        }

        // 4. Create Batch Transfers / Lifecycle Audit Trail Table
        if (! Schema::hasTable('batch_transfers')) {
            Schema::create('batch_transfers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('from_batch_id')->nullable()->constrained('batches')->nullOnDelete();
                $table->foreignId('to_batch_id')->nullable()->constrained('batches')->nullOnDelete();
                $table->string('action_type'); // enrolled, transferred, discontinued, rejoined, completed
                $table->text('reason')->nullable();
                $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('user_id');
                $table->index('action_type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('batch_transfers');
        Schema::dropIfExists('batch_students');
        Schema::dropIfExists('batches');

        Schema::table('courses', function (Blueprint $table) {
            if (Schema::hasColumn('courses', 'code')) {
                $table->dropColumn('code');
            }
        });
    }
};
