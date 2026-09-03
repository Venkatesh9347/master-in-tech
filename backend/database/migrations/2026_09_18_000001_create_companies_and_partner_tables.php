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
        // 1. Create Companies table
        if (! Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('logo')->nullable();
                $table->string('website')->nullable();
                $table->string('industry')->nullable();
                $table->string('company_size')->nullable(); // e.g. "1-50", "51-200", "201-1000", "1000+"
                $table->text('description')->nullable();
                $table->string('location')->nullable();
                $table->string('hr_name');
                $table->string('email')->unique();
                $table->string('phone');
                $table->json('hiring_technologies')->nullable();
                $table->text('hiring_requirements')->nullable();
                $table->text('message')->nullable();
                $table->string('status')->default('pending'); // pending, under_review, approved, rejected, suspended
                $table->text('rejection_reason')->nullable();
                $table->dateTime('approved_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('status');
                $table->index('email');
                $table->index('created_at');
            });
        }

        // 2. Add company_id and job attributes to placement_opportunities table
        if (Schema::hasTable('placement_opportunities')) {
            Schema::table('placement_opportunities', function (Blueprint $table) {
                if (! Schema::hasColumn('placement_opportunities', 'company_id')) {
                    $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
                }
                if (! Schema::hasColumn('placement_opportunities', 'work_mode')) {
                    $table->string('work_mode')->default('On-site')->after('employment_type'); // On-site, Remote, Hybrid
                }
                if (! Schema::hasColumn('placement_opportunities', 'preferred_skills')) {
                    $table->json('preferred_skills')->nullable()->after('skills_required');
                }
                if (! Schema::hasColumn('placement_opportunities', 'minimum_qualification')) {
                    $table->string('minimum_qualification')->nullable()->after('eligibility');
                }
                if (! Schema::hasColumn('placement_opportunities', 'additional_requirements')) {
                    $table->text('additional_requirements')->nullable()->after('selection_process');
                }
            });
        }

        // 3. Add company_id to users table for company accounts
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'company_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('role')->constrained('companies')->nullOnDelete();
            });
        }

        // 4. Create Placement Interviews table
        if (! Schema::hasTable('placement_interviews')) {
            Schema::create('placement_interviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('placement_application_id')->constrained('placement_applications')->cascadeOnDelete();
                $table->foreignId('placement_opportunity_id')->constrained('placement_opportunities')->cascadeOnDelete();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('candidate_id')->constrained('users')->cascadeOnDelete();
                $table->dateTime('interview_date');
                $table->string('interview_type')->default('online'); // online, offline, phone
                $table->string('meeting_link')->nullable();
                $table->string('location')->nullable();
                $table->text('instructions')->nullable();
                $table->string('status')->default('scheduled'); // scheduled, completed, cancelled
                $table->unsignedTinyInteger('technical_score')->nullable(); // 1 to 10
                $table->unsignedTinyInteger('communication_score')->nullable(); // 1 to 10
                $table->unsignedTinyInteger('overall_score')->nullable(); // 1 to 10
                $table->text('feedback')->nullable();
                $table->string('recommendation')->nullable(); // select, reject, further_round
                $table->text('interviewer_notes')->nullable();
                $table->foreignId('conducted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('company_id');
                $table->index('placement_application_id');
                $table->index('candidate_id');
                $table->index('status');
                $table->index('interview_date');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('placement_interviews');

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'company_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('company_id');
            });
        }

        if (Schema::hasTable('placement_opportunities')) {
            Schema::table('placement_opportunities', function (Blueprint $table) {
                if (Schema::hasColumn('placement_opportunities', 'company_id')) {
                    $table->dropConstrainedForeignId('company_id');
                }
                if (Schema::hasColumn('placement_opportunities', 'work_mode')) {
                    $table->dropColumn('work_mode');
                }
                if (Schema::hasColumn('placement_opportunities', 'preferred_skills')) {
                    $table->dropColumn('preferred_skills');
                }
                if (Schema::hasColumn('placement_opportunities', 'minimum_qualification')) {
                    $table->dropColumn('minimum_qualification');
                }
                if (Schema::hasColumn('placement_opportunities', 'additional_requirements')) {
                    $table->dropColumn('additional_requirements');
                }
            });
        }

        Schema::dropIfExists('companies');
    }
};
