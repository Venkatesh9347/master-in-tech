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
        if (Schema::hasTable('class_sessions')) {
            Schema::table('class_sessions', function (Blueprint $table) {
                $table->index(['course_id', 'status', 'scheduled_date'], 'cs_course_status_sched_idx');
                $table->index(['tutor_id', 'status', 'scheduled_date'], 'cs_tutor_status_sched_idx');
            });
        }

        if (Schema::hasTable('class_materials')) {
            Schema::table('class_materials', function (Blueprint $table) {
                $table->index(['course_id', 'class_session_id'], 'cm_course_session_idx');
                $table->index('uploaded_by', 'cm_uploaded_by_idx');
            });
        }

        if (Schema::hasTable('course_enrollments')) {
            Schema::table('course_enrollments', function (Blueprint $table) {
                $table->index(['user_id', 'status'], 'ce_user_status_idx');
            });
        }

        if (Schema::hasTable('quiz_attempts')) {
            Schema::table('quiz_attempts', function (Blueprint $table) {
                $table->index(['user_id', 'quiz_id'], 'qa_user_quiz_idx');
            });
        }

        if (Schema::hasTable('class_session_attendances')) {
            Schema::table('class_session_attendances', function (Blueprint $table) {
                $table->index(['user_id', 'class_session_id'], 'csa_user_session_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('class_sessions')) {
            Schema::table('class_sessions', function (Blueprint $table) {
                $table->dropIndex('cs_course_status_sched_idx');
                $table->dropIndex('cs_tutor_status_sched_idx');
            });
        }

        if (Schema::hasTable('class_materials')) {
            Schema::table('class_materials', function (Blueprint $table) {
                $table->dropIndex('cm_course_session_idx');
                $table->dropIndex('cm_uploaded_by_idx');
            });
        }

        if (Schema::hasTable('course_enrollments')) {
            Schema::table('course_enrollments', function (Blueprint $table) {
                $table->dropIndex('ce_user_status_idx');
            });
        }

        if (Schema::hasTable('quiz_attempts')) {
            Schema::table('quiz_attempts', function (Blueprint $table) {
                $table->dropIndex('qa_user_quiz_idx');
            });
        }

        if (Schema::hasTable('class_session_attendances')) {
            Schema::table('class_session_attendances', function (Blueprint $table) {
                $table->dropIndex('csa_user_session_idx');
            });
        }
    }
};
