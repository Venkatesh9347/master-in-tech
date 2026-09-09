<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurriculumIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;
    private Section $section;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->course = Course::create([
            'title' => 'Integrity Course',
            'slug' => 'integrity-course-' . uniqid(),
            'description' => 'Desc',
            'category' => 'Data Science',
            'instructor' => $this->admin->name,
            'instructor_id' => $this->admin->id,
            'price' => 100.0,
            'duration' => '6 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ]);
        $this->section = Section::create([
            'course_id' => $this->course->id,
            'title' => 'Module A',
            'sort_order' => 1,
        ]);
    }

    public function test_lesson_type_must_match_database_enum(): void
    {
        foreach (['article', 'project', 'homework'] as $invalidType) {
            $this->actingAs($this->admin, 'sanctum')
                ->postJson("/api/courses/{$this->course->id}/sections/{$this->section->id}/lessons", [
                    'title' => 'Invalid Lesson',
                    'type' => $invalidType,
                ])
                ->assertStatus(422);
        }
    }

    public function test_lesson_type_assignment_is_accepted(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/courses/{$this->course->id}/sections/{$this->section->id}/lessons", [
                'title' => 'Practical Assignment',
                'type' => 'assignment',
                'sort_order' => 1,
            ])
            ->assertStatus(201)
            ->assertJsonPath('type', 'assignment');
    }

    public function test_lesson_progress_lesson_reference_has_foreign_key(): void
    {
        $foreignKeys = \Illuminate\Support\Facades\Schema::getForeignKeys('lesson_progress');

        $this->assertTrue(
            collect($foreignKeys)->contains(
                fn (array $fk) => $fk['columns'] === ['lesson_id'] && $fk['foreign_table'] === 'lessons'
            ),
            'lesson_progress.lesson_id must reference lessons.id'
        );
    }

    public function test_deleting_a_lesson_without_student_history_succeeds(): void
    {
        $lesson = Lesson::create([
            'course_id' => $this->course->id,
            'section_id' => $this->section->id,
            'title' => 'Lesson One',
            'type' => 'text',
            'sort_order' => 1,
        ]);

        $this->assertDatabaseHas('lessons', ['id' => $lesson->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/courses/{$this->course->id}/sections/{$this->section->id}/lessons/{$lesson->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('lessons', ['id' => $lesson->id]);
    }

    public function test_deleting_a_lesson_with_student_history_is_blocked(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $this->course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $lesson = Lesson::create([
            'course_id' => $this->course->id,
            'section_id' => $this->section->id,
            'title' => 'Lesson One',
            'type' => 'text',
            'sort_order' => 1,
        ]);
        LessonProgress::create([
            'user_id' => $student->id,
            'course_id' => $this->course->id,
            'lesson_id' => $lesson->id,
            'completed' => true,
            'completed_at' => now(),
            'started' => true,
            'last_accessed_at' => now(),
            'progress_percentage' => 100.0,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/courses/{$this->course->id}/sections/{$this->section->id}/lessons/{$lesson->id}")
            ->assertStatus(422)
            ->assertJsonPath('delete_blocked', true);

        $this->assertDatabaseHas('lessons', ['id' => $lesson->id]);
        $this->assertDatabaseHas('lesson_progress', ['lesson_id' => $lesson->id]);
    }
}