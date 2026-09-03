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
        // 1. Placement Opportunities (Job Listings)
        if (! Schema::hasTable('placement_opportunities')) {
            Schema::create('placement_opportunities', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('company_name');
                $table->string('company_logo')->nullable();
                $table->string('job_code')->nullable();
                $table->string('location')->default('Remote / Hybrid');
                $table->string('employment_type')->default('Full-time'); // Full-time, Internship, Contract
                $table->string('salary_package')->nullable(); // e.g. "8.5 - 12.0 LPA"
                $table->string('experience_required')->nullable(); // e.g. "Freshers / 0-2 Years"
                $table->json('eligible_courses')->nullable(); // Array of course IDs or codes
                $table->json('eligible_batches')->nullable(); // Array of batch IDs or codes
                $table->json('skills_required')->nullable(); // Array of skill tags
                $table->longText('description');
                $table->text('selection_process')->nullable();
                $table->unsignedInteger('openings_count')->default(1);
                $table->date('deadline_date')->nullable();
                $table->string('status')->default('published'); // published, draft, closed
                $table->boolean('is_featured')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('status');
                $table->index('deadline_date');
                $table->index('created_at');
            });
        }

        // 2. Placement Applications
        if (! Schema::hasTable('placement_applications')) {
            Schema::create('placement_applications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('placement_opportunity_id')->constrained('placement_opportunities')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
                $table->string('batch_code');
                $table->string('student_name');
                $table->string('email');
                $table->string('phone');
                $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
                $table->string('course_title')->nullable();
                $table->text('resume_url');
                $table->text('cover_note')->nullable();
                $table->string('status')->default('applied'); // applied, under_review, shortlisted, interview_scheduled, selected, rejected, joined
                $table->dateTime('interview_date')->nullable();
                $table->text('interview_notes')->nullable();
                $table->text('admin_notes')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('applied_at');
                $table->timestamps();

                $table->unique(['placement_opportunity_id', 'user_id'], 'uniq_placement_app_opp_user');
                $table->index('user_id');
                $table->index('batch_id');
                $table->index('status');
                $table->index('applied_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('placement_applications');
        Schema::dropIfExists('placement_opportunities');
    }
};
