<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchB2AssignmentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private User $admin;
    private Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $course = Course::create([
            'title' => 'B2 Assign Course',
            'slug' => 'b2-assign-course-' . uniqid(),
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

    private function submit(string $text = 'v1', array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->student, 'sanctum')->postJson("/api/assignments/{$this->assignment->id}/submit", array_merge([
            'submission_text' => $text,
        ], $extra));
    }

    private function grade(int $submissionId, int $score, string $status): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/assignments/submissions/{$submissionId}/grade", [
            'score' => $score,
            'feedback' => 'feedback',
            'status' => $status,
        ]);
    }

    public function test_graded_submission_cannot_be_silently_overwritten(): void
    {
        $res = $this->submit('final version');
        $res->assertCreated();
        $id = $res->json('submission.id');

        $this->grade($id, 95, 'graded')->assertOk();

        // Student tries to resubmit a graded submission -> blocked.
        $this->submit('attempt to overwrite graded work')->assertStatus(409);

        // Grading evidence and content must be preserved.
        $row = AssignmentSubmission::findOrFail($id);
        $this->assertSame('graded', $row->status);
        $this->assertSame('final version', $row->submission_text);
        $this->assertSame('95.00', (string) $row->score);
    }

    public function test_client_cannot_supply_submitted_at_to_bypass_deadline(): void
    {
        $clientTimestamp = '2020-01-01 00:00:00'; // a forged timestamp

        $this->submit('work', ['submitted_at' => $clientTimestamp])->assertCreated();

        $row = AssignmentSubmission::firstOrFail();
        // The server generated submitted_at (near now), it did NOT store the client value.
        $this->assertNotSame($clientTimestamp, $row->submitted_at->format('Y-m-d H:i:s'));
        $this->assertTrue($row->submitted_at->isFuture() || $row->submitted_at->gte(now()->subMinute()));
    }

    public function test_returned_submission_can_be_revised(): void
    {
        $res = $this->submit('first draft');
        $res->assertCreated();
        $id = $res->json('submission.id');

        $this->grade($id, 60, 'returned')->assertOk();

        // Returned work can be resubmitted for revision.
        $this->submit('revised after feedback')->assertOk();

        $row = AssignmentSubmission::findOrFail($id);
        $this->assertSame('submitted', $row->status);
        $this->assertSame('revised after feedback', $row->submission_text);
    }

    public function test_pending_submission_can_be_revised_before_grading(): void
    {
        $res = $this->submit('v1');
        $res->assertCreated();
        $id = $res->json('submission.id');

        $this->submit('v2')->assertOk();

        $row = AssignmentSubmission::findOrFail($id);
        $this->assertSame('submitted', $row->status);
        $this->assertSame('v2', $row->submission_text);
    }

    public function test_submission_blocked_when_due_date_has_passed(): void
    {
        // Set the due date in the past.
        $this->assignment->update(['due_date' => now()->subDay()]);

        $this->submit('late work')->assertStatus(403);
    }

    public function test_late_submission_response_indicates_past_due(): void
    {
        $this->assignment->update(['due_date' => now()->subHour()]);

        $res = $this->submit('late work');
        $res->assertStatus(403);
        $res->assertJsonPath('past_due', true);
        $res->assertJsonStructure(['message', 'past_due', 'due_date']);
    }

    public function test_submission_allowed_before_due_date(): void
    {
        // due_date is set to now + 7 days in setUp.
        $this->submit('on-time work')->assertCreated();
    }

    public function test_submission_allowed_on_due_date_boundary(): void
    {
        // Reference: now == due date should still be allowed (not strictly greater).
        $this->assignment->update(['due_date' => now()->addMinute()]);

        $this->submit('just in time')->assertCreated();
    }

    public function test_submission_allowed_when_no_due_date_set(): void
    {
        $this->assignment->update(['due_date' => null]);

        $this->submit('no deadline work')->assertCreated();
    }

    public function test_update_of_existing_submission_also_blocked_after_due_date(): void
    {
        // Submit while open.
        $this->submit('first version')->assertCreated();

        // Freeze the clock after the deadline and attempt an update to an existing
        // (ungraded) submission -> must also be hard blocked.
        $this->assignment->update(['due_date' => now()->subDay()]);

        $this->submit('late revision')->assertStatus(403);
    }
}
