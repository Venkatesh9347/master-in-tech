<?php

/*
|--------------------------------------------------------------------------
| Extend LMS enum columns (GA blocker R3)
|--------------------------------------------------------------------------
| The frontend legitimately offers 'article' / 'project' lesson types and
| 'pending' / 'cancelled' enrollment states, and the API validations already
| accept them. The database enums were narrower, so writes via the API hit a
| strict-mode 500. This migration widens the enums per driver:
|
|   * sqlite  — enum compiles to a CHECK constraint; Laravel `change()` copies
|               the table with the widened constraint (exercised in CI).
|   * pgsql   — enum compiles to a CHECK constraint; the constraint must be
|               dropped before widening, then the new check is applied.
|   * mysql   — `ALTER TABLE ... MODIFY COLUMN` with the widened ENUM list.
|
| MySQL/PostgreSQL paths are untested in this repository's CI (SQLite only);
| they are provided for the intended deployments.
*/

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LESSON_TYPES = ['video', 'text', 'document', 'quiz', 'assignment', 'article', 'project'];

    private const LESSON_TYPES_LEGACY = ['video', 'text', 'document', 'quiz', 'assignment'];

    private const ENROLLMENT_STATUSES = ['active', 'completed', 'dropped', 'pending', 'cancelled'];

    private const ENROLLMENT_STATUSES_LEGACY = ['active', 'completed', 'dropped'];

    public function up(): void
    {
        match (Schema::getConnection()->getDriverName()) {
            'sqlite' => $this->sqlite(self::LESSON_TYPES, self::ENROLLMENT_STATUSES),
            'pgsql' => $this->pgsql(self::LESSON_TYPES, self::ENROLLMENT_STATUSES),
            default => $this->mysql(self::LESSON_TYPES, self::ENROLLMENT_STATUSES),
        };
    }

    public function down(): void
    {
        match (Schema::getConnection()->getDriverName()) {
            'sqlite' => $this->sqlite(self::LESSON_TYPES_LEGACY, self::ENROLLMENT_STATUSES_LEGACY),
            'pgsql' => $this->pgsql(self::LESSON_TYPES_LEGACY, self::ENROLLMENT_STATUSES_LEGACY),
            default => $this->mysql(self::LESSON_TYPES_LEGACY, self::ENROLLMENT_STATUSES_LEGACY),
        };
    }

    private function sqlite(array $lessonTypes, array $enrollmentStatuses): void
    {
        Schema::table('lessons', function (Blueprint $table) use ($lessonTypes) {
            $table->enum('type', $lessonTypes)->default('text')->change();
        });

        Schema::table('course_enrollments', function (Blueprint $table) use ($enrollmentStatuses) {
            $table->enum('status', $enrollmentStatuses)->default('active')->change();
        });
    }

    private function pgsql(array $lessonTypes, array $enrollmentStatuses): void
    {
        // Laravel's Schema Builder emits the CHECK inline inside
        // ALTER COLUMN ... TYPE on PostgreSQL, which is invalid syntax.
        // Manage the transition with explicit statements instead; value
        // lists come from the method parameters so up() and down() stay
        // symmetric. The IF EXISTS guard keeps reruns safe.
        $lessonList = implode(',', array_map(fn ($value) => "'{$value}'", $lessonTypes));
        $statusList = implode(',', array_map(fn ($value) => "'{$value}'", $enrollmentStatuses));

        DB::statement('ALTER TABLE lessons DROP CONSTRAINT IF EXISTS lessons_type_check');
        DB::statement('ALTER TABLE lessons ALTER COLUMN type TYPE varchar(255)');
        DB::statement("ALTER TABLE lessons ADD CONSTRAINT lessons_type_check CHECK (type IN ({$lessonList}))");
        DB::statement("ALTER TABLE lessons ALTER COLUMN type SET DEFAULT 'text'");

        DB::statement('ALTER TABLE course_enrollments DROP CONSTRAINT IF EXISTS course_enrollments_status_check');
        DB::statement('ALTER TABLE course_enrollments ALTER COLUMN status TYPE varchar(255)');
        DB::statement("ALTER TABLE course_enrollments ADD CONSTRAINT course_enrollments_status_check CHECK (status IN ({$statusList}))");
        DB::statement("ALTER TABLE course_enrollments ALTER COLUMN status SET DEFAULT 'active'");
    }

    private function mysql(array $lessonTypes, array $enrollmentStatuses): void
    {
        $lessonEnum = implode("','", $lessonTypes);
        $statusEnum = implode("','", $enrollmentStatuses);

        DB::statement("ALTER TABLE lessons MODIFY COLUMN type ENUM('{$lessonEnum}') NOT NULL DEFAULT 'text'");
        DB::statement("ALTER TABLE course_enrollments MODIFY COLUMN status ENUM('{$statusEnum}') NOT NULL DEFAULT 'active'");
    }
};