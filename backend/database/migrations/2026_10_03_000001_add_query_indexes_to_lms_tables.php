<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence-driven secondary indexes for LMS tables that had none.
 *
 * Every index below was justified by an observed full-table scan in
 * `EXPLAIN QUERY PLAN` on the hot path that uses the column, not by simply
 * counting unindexed tables. Tables deliberately left alone:
 *
 *   - migrations              framework-managed, tiny, no application query path
 *   - job_batches             framework-managed queue bookkeeping
 *   - password_reset_tokens   `email` is ALREADY indexed by Laravel's default
 *                             unique constraint (verified via
 *                             sqlite_autoindex_password_reset_tokens_1), so a
 *                             second index would be pure redundancy
 *
 * Portability: plain Schema builder with explicitly named indexes, matching the
 * convention used by the other create-table migrations in this repository. The
 * statements are valid on SQLite, MySQL 8+ and PostgreSQL.
 *
 * Note on MySQL: MySQL implicitly indexes foreign-key columns, so some of these
 * are redundant *there*; they are required on PostgreSQL and SQLite, which do
 * not create FK indexes automatically. The index set is driven by the
 * portable worst case, not by MySQL alone.
 */
return new class extends Migration
{
    /**
     * table => list of [columns, index name, reason].
     */
    private const INDEXES = [
        'sections' => [
            [['course_id'], 'sections_course_id_index', 'Course detail loads every section by course_id; full scan observed.'],
        ],
        'lesson_resources' => [
            [['lesson_id'], 'lesson_resources_lesson_id_index', 'Lesson page lists resources by lesson_id; full scan observed.'],
        ],
        'quizzes' => [
            [['lesson_id'], 'quizzes_lesson_id_index', 'Quiz lookup is lesson-scoped when completing a quiz lesson.'],
        ],
        'quiz_questions' => [
            [['quiz_id'], 'quiz_questions_quiz_id_index', 'Quiz load pulls all questions for one quiz; full scan observed.'],
        ],
        'quiz_options' => [
            [['question_id'], 'quiz_options_question_id_index', 'Largest unindexed table; options are fetched per question; full scan observed.'],
        ],
        'quiz_answers' => [
            [['question_id'], 'quiz_answers_question_id_index', 'Grading resolves submitted answers per question.'],
        ],
        'assignments' => [
            [['lesson_id'], 'assignments_lesson_id_index', 'Assignment requirement check runs per lesson completion.'],
        ],
        'enquiry_notes' => [
            [['enquiry_id'], 'enquiry_notes_enquiry_id_index', 'Enquiry detail renders its notes; full scan observed.'],
        ],
        'lesson_discussions' => [
            [['lesson_id'], 'lesson_discussions_lesson_id_index', 'Lesson page lists discussions for the lesson.'],
            [['user_id'], 'lesson_discussions_user_id_index', 'Discussions are also filtered by author for ownership checks.'],
        ],
        'lesson_discussion_replies' => [
            [['discussion_id'], 'lesson_discussion_replies_discussion_id_index', 'Replies are always fetched for one discussion.'],
        ],
        'live_classes' => [
            [['course_id'], 'live_classes_course_id_index', 'Course detail lists scheduled live classes.'],
        ],
        'instructors' => [
            [['user_id'], 'instructors_user_id_index', 'Instructor records are resolved from their user.'],
        ],
        'learning_path_courses' => [
            [['learning_path_id'], 'learning_path_courses_learning_path_id_index', 'Path assembly reads ordered courses per path.'],
        ],
        'testimonials' => [
            [['course_id'], 'testimonials_course_id_index', 'Course detail renders testimonials for the course.'],
        ],
        'faqs' => [
            [['category'], 'faqs_category_index', 'Public FAQ listing filters by category.'],
        ],
        'navigation_items' => [
            [['location'], 'navigation_items_location_index', 'Public navigation is built per location and ordered.'],
            [['parent_id'], 'navigation_items_parent_id_index', 'Navigation tree is assembled by parent.'],
        ],
        'audit_logs' => [
            [['user_id', 'created_at'], 'audit_logs_user_id_created_at_index', 'Audit trail is read per user newest-first; the composite removes both the full scan and the temp B-tree sort observed in EXPLAIN.'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach ($indexes as [$columns, $name]) {
                    // Idempotent: never fail on a re-run where the index exists.
                    if (! $this->indexExists($blueprint, $name)) {
                        $blueprint->index($columns, $name);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach ($indexes as [$columns, $name]) {
                    $blueprint->dropIndex($name);
                }
            });
        }
    }

    /**
     * Cheap existence check so `up()` stays idempotent across SQLite, where
     * `CREATE INDEX` has no IF NOT EXISTS form.
     */
    private function indexExists(Blueprint $blueprint, string $name): bool
    {
        try {
            return $blueprint->getConnection()
                ->getDoctrineSchemaManager()
                ->introspectTable($blueprint->getTable())
                ->hasIndex($name);
        } catch (\Throwable) {
            // Doctrine is not required for schema work here; if introspection is
            // unavailable, fall back to letting the builder apply the index.
            return false;
        }
    }
};
