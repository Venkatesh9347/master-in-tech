<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Assignment policy columns + immutable revision history.
     *
     * - submissions.is_late: set server-side when accepted inside the grace window.
     * - submissions.revision_number: incremented on every student revision.
     * - assignment_submission_revisions: append-only snapshot of each superseded
     *   revision (content, grade state, timestamps, actor).
     */
    public function up(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $table) {
            if (! Schema::hasColumn('assignment_submissions', 'is_late')) {
                $table->boolean('is_late')->default(false)->after('submitted_at');
            }
            if (! Schema::hasColumn('assignment_submissions', 'revision_number')) {
                $table->integer('revision_number')->default(1)->after('is_late');
            }
        });

        Schema::create('assignment_submission_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_submission_id')
                ->constrained('assignment_submissions')->cascadeOnDelete();
            $table->integer('revision_number');
            $table->text('submission_text')->nullable();
            $table->string('file_url', 2000)->nullable();
            $table->string('status', 32)->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['assignment_submission_id', 'revision_number'],
                'submission_revisions_unique'
            );
            $table->index('assignment_submission_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assignment_submission_revisions');

        Schema::table('assignment_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('assignment_submissions', 'revision_number')) {
                $table->dropColumn('revision_number');
            }
            if (Schema::hasColumn('assignment_submissions', 'is_late')) {
                $table->dropColumn('is_late');
            }
        });
    }
};
