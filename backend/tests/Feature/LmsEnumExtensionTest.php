<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GA blocker R3: the frontend intentionally offers 'article'/'project' lesson
 * types and 'pending'/'cancelled' enrollment states. The API validation already
 * accepts them; the DB enums have been widened to match so writes no longer
 * crash with a strict-mode 500, while genuinely invalid values are still
 * rejected (validation → 422, DB constraint → QueryException).
 */
class LmsEnumExtensionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function course(): Course
    {
        return Course::create([
            'title' => 'Enum Extension Course',
            'slug' => 'enum-extension-course',
            'description' => 'LMS enum regression course',
            'instructor' => 'QA Faculty',
            'price' => 1999,
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
        ]);
    }

    private function section(Course $course): Section
    {
        return Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1',
            'sort_order' => 0,
            'is_published' => true,
        ]);
    }

    /* ---------------- Lessons: article / project ---------------- */

    public function test_article_and_project_lesson_types_store_without_error(): void
    {
        $admin = $this->admin();
        $course = $this->course();
        $section = $this->section($course);

        $article = $this->actingAs($admin, 'sanctum')->postJson(
            "/api/courses/{$course->id}/sections/{$section->id}/lessons",
            ['title' => 'Read Me', 'type' => 'article']
        );
        $article->assertCreated();
        $article->assertJsonPath('type', 'article');

        $project = $this->actingAs($admin, 'sanctum')->postJson(
            "/api/courses/{$course->id}/sections/{$section->id}/lessons",
            ['title' => 'Capstone', 'type' => 'project']
        );
        $project->assertCreated();
        $project->assertJsonPath('type', 'project');

        $this->assertDatabaseHas('lessons', ['title' => 'Read Me', 'type' => 'article']);
        $this->assertDatabaseHas('lessons', ['title' => 'Capstone', 'type' => 'project']);
    }

    public function test_bogus_lesson_type_is_rejected_by_validation(): void
    {
        $admin = $this->admin();
        $course = $this->course();
        $section = $this->section($course);

        $res = $this->actingAs($admin, 'sanctum')->postJson(
            "/api/courses/{$course->id}/sections/{$section->id}/lessons",
            ['title' => 'Bad Lesson', 'type' => 'bogus']
        );

        $res->assertStatus(422);
        $this->assertSame(0, Lesson::where('title', 'Bad Lesson')->count());
    }

    public function test_db_constraint_still_rejects_invalid_lesson_type(): void
    {
        $course = $this->course();
        $section = $this->section($course);

        $this->expectException(QueryException::class);

        Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Should Never Insert',
            'type' => 'bogus',
        ]);
    }

    /* ---------------- Enrollments: pending / cancelled ---------------- */

    public function test_pending_and_cancelled_enrollment_statuses_store_without_error(): void
    {
        $admin = $this->admin();
        $studentA = User::factory()->create(['role' => 'student']);
        $studentB = User::factory()->create(['role' => 'student']);
        $course = $this->course();

        $pending = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/enrollments', [
            'user_id' => $studentA->id,
            'course_id' => $course->id,
            'status' => 'pending',
        ]);
        $pending->assertCreated();
        $pending->assertJsonPath('enrollment.status', 'pending');

        $cancelled = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/enrollments', [
            'user_id' => $studentB->id,
            'course_id' => $course->id,
            'status' => 'cancelled',
        ]);
        $cancelled->assertCreated();
        $cancelled->assertJsonPath('enrollment.status', 'cancelled');

        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $studentA->id,
            'course_id' => $course->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $studentB->id,
            'course_id' => $course->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_bogus_enrollment_status_is_rejected_by_validation(): void
    {
        $admin = $this->admin();
        $student = User::factory()->create(['role' => 'student']);
        $course = $this->course();

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'on-hold',
        ]);

        $res->assertStatus(422);
        $this->assertSame(0, CourseEnrollment::where('user_id', $student->id)->count());
    }
}