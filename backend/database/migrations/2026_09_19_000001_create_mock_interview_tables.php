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
        // 1. Professional Interviewers Table
        if (! Schema::hasTable('mock_interviewers')) {
            Schema::create('mock_interviewers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('phone')->nullable();
                $table->string('designation');
                $table->string('company');
                $table->decimal('years_of_experience', 4, 1)->default(0);
                $table->json('skills')->nullable();
                $table->text('bio')->nullable();
                $table->text('internal_notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('is_active');
                $table->index('company');
                $table->index('created_at');
            });
        }

        // 2. Mock Interview Slots Table
        if (! Schema::hasTable('mock_interview_slots')) {
            Schema::create('mock_interview_slots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('interviewer_id')->constrained('mock_interviewers')->cascadeOnDelete();
                $table->date('slot_date');
                $table->time('start_time');
                $table->time('end_time');
                $table->unsignedSmallInteger('duration_minutes')->default(45);
                $table->string('meeting_link')->nullable();
                $table->string('platform')->default('Google Meet / Online');
                $table->string('status')->default('available'); // available, booked, completed, cancelled
                $table->text('instructions')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['interviewer_id', 'slot_date', 'start_time'], 'uniq_interviewer_slot_time');
                $table->index('slot_date');
                $table->index('status');
                $table->index('interviewer_id');
            });
        }

        // 3. Mock Interviews (Bookings & Schedule) Table
        if (! Schema::hasTable('mock_interviews')) {
            Schema::create('mock_interviews', function (Blueprint $table) {
                $table->id();
                $table->string('booking_code')->unique();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('slot_id')->constrained('mock_interview_slots')->cascadeOnDelete();
                $table->foreignId('interviewer_id')->constrained('mock_interviewers')->cascadeOnDelete();
                $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();
                $table->dateTime('scheduled_at');
                $table->string('status')->default('booked'); // booked, confirmed, completed, cancelled, rescheduled, no_show
                $table->text('student_notes')->nullable();
                $table->text('admin_notes')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('cancelled_at')->nullable();
                $table->foreignId('rescheduled_from_id')->nullable()->constrained('mock_interviews')->nullOnDelete();
                $table->timestamps();

                $table->index('student_id');
                $table->index('interviewer_id');
                $table->index('status');
                $table->index('scheduled_at');
            });
        }

        // 4. Mock Interview Evaluations Table
        if (! Schema::hasTable('mock_interview_evaluations')) {
            Schema::create('mock_interview_evaluations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mock_interview_id')->unique()->constrained('mock_interviews')->cascadeOnDelete();
                $table->foreignId('interviewer_id')->nullable()->constrained('mock_interviewers')->nullOnDelete();
                $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedTinyInteger('technical_knowledge')->default(5); // 1-10
                $table->unsignedTinyInteger('programming_problem_solving')->default(5); // 1-10
                $table->unsignedTinyInteger('communication')->default(5); // 1-10
                $table->unsignedTinyInteger('confidence')->default(5); // 1-10
                $table->unsignedTinyInteger('project_knowledge')->default(5); // 1-10
                $table->unsignedTinyInteger('interview_readiness')->default(5); // 1-10
                $table->decimal('overall_rating', 3, 1)->default(5.0);
                $table->text('strengths');
                $table->text('areas_for_improvement');
                $table->text('interviewer_remarks')->nullable();
                $table->string('recommendation'); // Ready for Placement, Needs Improvement, Re-interview Required
                $table->boolean('is_published_to_student')->default(true);
                $table->dateTime('evaluated_at');
                $table->timestamps();

                $table->index('student_id');
                $table->index('recommendation');
                $table->index('evaluated_at');
            });
        }

        // 5. Student Placement Eligibilities Table
        if (! Schema::hasTable('student_placement_eligibilities')) {
            Schema::create('student_placement_eligibilities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->boolean('course_completed')->default(false);
                $table->string('mock_interview_status')->default('pending'); // pending, booked, completed, passed, failed
                $table->boolean('placement_eligible')->default(false);
                $table->boolean('is_admin_override')->default(false);
                $table->text('override_reason')->nullable();
                $table->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->dateTime('last_evaluated_at')->nullable();
                $table->timestamps();

                $table->index('placement_eligible');
                $table->index('course_completed');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_placement_eligibilities');
        Schema::dropIfExists('mock_interview_evaluations');
        Schema::dropIfExists('mock_interviews');
        Schema::dropIfExists('mock_interview_slots');
        Schema::dropIfExists('mock_interviewers');
    }
};
