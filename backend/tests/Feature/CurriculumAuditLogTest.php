<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Curriculum mutations (sections/lessons) must leave an audit trail:
 * who changed what, with before/after values.
 */
class CurriculumAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourse(): Course
    {
        return Course::create([
            'title' => 'Audit Fixture Course',
            'slug' => 'audit-fixture-course',
            'description' => 'Audit log fixture.',
            'category' => 'Full Stack',
            'instructor' => 'Fixture',
            'duration' => '4 Weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);
    }

    public function test_section_lifecycle_writes_audit_logs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->makeCourse();
        Sanctum::actingAs($admin);

        $create = $this->postJson("/api/courses/{$course->id}/sections", [
            'title' => 'Audited Module',
        ])->assertStatus(201);
        $sectionId = $create->json('id');

        $this->putJson("/api/courses/{$course->id}/sections/{$sectionId}", [
            'title' => 'Audited Module Renamed',
        ])->assertOk();

        $this->postJson("/api/courses/{$course->id}/sections/{$sectionId}/toggle-publish", [])
            ->assertOk();

        $this->deleteJson("/api/courses/{$course->id}/sections/{$sectionId}")
            ->assertOk();

        foreach (['created_section', 'updated_section', 'toggled_section_publish', 'deleted_section'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
    }

    public function test_lesson_lifecycle_and_reorder_write_audit_logs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->makeCourse();
        Sanctum::actingAs($admin);

        $section = Section::create([
            'course_id' => $course->id, 'title' => 'Module', 'sort_order' => 1, 'is_published' => true,
        ]);

        $create = $this->postJson("/api/courses/{$course->id}/sections/{$section->id}/lessons", [
            'title' => 'Audited Lesson',
            'type' => 'text',
        ])->assertStatus(201);
        $lessonId = $create->json('id');

        $this->putJson("/api/courses/{$course->id}/sections/{$section->id}/lessons/{$lessonId}", [
            'title' => 'Audited Lesson Renamed',
        ])->assertOk();

        $this->postJson("/api/courses/{$course->id}/reorder", [
            'sections' => [['id' => $section->id, 'sort_order' => 1]],
            'lessons' => [['id' => $lessonId, 'sort_order' => 1]],
        ])->assertOk();

        $this->deleteJson("/api/courses/{$course->id}/sections/{$section->id}/lessons/{$lessonId}")
            ->assertOk();

        foreach (['created_lesson', 'updated_lesson', 'reordered_curriculum', 'deleted_lesson'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }

        $this->assertSame(0, Lesson::where('id', $lessonId)->count());
    }
}
