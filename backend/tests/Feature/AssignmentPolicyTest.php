<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionRevision;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Server-side assignment policy: due dates, grace-window late state,
 * revision numbering with immutable history, first-submitted_at preserved,
 * client timestamps ignored, graded overwrite protection intact.
 */
class AssignmentPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['assignments.late_grace_minutes' => 0]);

        $this->student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $course = Course::create([
            'title' => 'Policy Course',
            'slug' => 'policy-course-' . uniqid(),
            'description' => 'desc',
            'instructor' => 'Tutor',
            'price' => 100,
            'duration' => '1 week',
            'difficulty' => 'Beginner',
            'is_published' => true,
        ]);
        CourseEnrollment::create(['user_id' => $this->student->id, 'course_id' => $course->id, 'status' => 'active']);

        $section = Section::create(['course_id' => $course->id, 'title' => 'S', 'sort_order' => 0, 'is_published' => true]);
        $lesson = Lesson::create(['course_id' => $course->id, 'section_id' => $section->id, 'title' => 'L', 'type' => 'assignment', 'sort_order' => 0, 'is_published' => true]);

        $this->assignment = Assignment::create([
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'title' => 'Project',
            'instructions' => 'Build it',
            'due_date' => now()->addDays(7),
            'max_marks' => 100,
            'is_published' => true,
        ]);
    }

    private function submit(array $payload = ['submission_text' => 'v1'])
    {
        return $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/assignments/{$this->assignment->id}/submit", $payload);
    }

    public function test_on_time_submit_is_not_late_revision_one(): void
    {
        $res = $this->submit()->assertCreated();

        $this->assertFalse((bool) $res->json('submission.is_late'));
        $this->assertSame(1, (int) $res->json('submission.revision_number'));
        $this->assertNotNull($res->json('submission.submitted_at'));
    }

    public function test_revision_bumps_number_and_snapshots_history(): void
    {
        $this->submit(['submission_text' => 'v1'])->assertCreated();
        $first = AssignmentSubmission::firstOrFail();
        $firstSubmittedAt = $first->submitted_at->toISOString();

        sleep(1);
        $this->submit(['submission_text' => 'v2'])->assertOk();

        $row = AssignmentSubmission::firstOrFail();
        $this->assertSame('v2', $row->submission_text);
        $this->assertSame(2, (int) $row->revision_number);
        // First-submission timestamp preserved, not rewritten.
        $this->assertSame($firstSubmittedAt, $row->submitted_at->toISOString());

        $this->assertDatabaseHas('assignment_submission_revisions', [
            'assignment_submission_id' => $row->id,
            'revision_number' => 1,
            'submission_text' => 'v1',
        ]);
    }

    public function test_grace_window_accepts_late_flagged_beyond_blocks(): void
    {
        $this->assignment->update(['due_date' => now()->subMinutes(30)]);

        // Default grace 0: hard block preserved.
        $this->submit()->assertForbidden()->assertJson(['past_due' => true]);

        config(['assignments.late_grace_minutes' => 60]);
        $res = $this->submit(['submission_text' => 'late but allowed'])->assertCreated();
        $this->assertTrue((bool) $res->json('submission.is_late'));

        // Beyond grace: blocked again.
        $this->assignment->update(['due_date' => now()->subHours(3)]);
        $this->submit()->assertForbidden();
    }

    public function test_client_submitted_at_is_ignored(): void
    {
        $res = $this->submit([
            'submission_text' => 'v1',
            'submitted_at' => now()->subYear()->toISOString(),
        ])->assertCreated();

        $stored = AssignmentSubmission::findOrFail($res->json('submission.id'));
        $this->assertTrue($stored->submitted_at->greaterThan(now()->subMinutes(5)));
    }
}
