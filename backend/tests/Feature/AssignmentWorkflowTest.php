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

class AssignmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_submit_and_admin_can_grade_assignment(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $admin = User::factory()->create(['role' => 'admin']);

        $course = Course::create([
            'title' => 'Project Course',
            'slug' => 'project-course',
            'description' => 'Course with practical projects',
            'instructor' => 'Lead Engineer',
            'price' => 2000,
            'duration' => '2 weeks',
            'difficulty' => 'Intermediate',
        ]);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Capstone Module',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Portfolio Project',
            'type' => 'assignment',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $assignment = Assignment::create([
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'title' => 'Build Full Stack LMS',
            'instructions' => 'Create a functioning LMS with React and Laravel',
            'max_marks' => 100,
            'is_published' => true,
        ]);

        // 1. Student submits assignment
        $submitRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/assignments/{$assignment->id}/submit", [
                'submission_text' => 'Implemented complete Phase 3 LMS modules.',
                'file_url' => 'https://github.com/student/lms-repo',
            ]);

        $submitRes->assertCreated();
        $this->assertDatabaseHas('assignment_submissions', [
            'user_id' => $student->id,
            'assignment_id' => $assignment->id,
            'status' => 'submitted',
        ]);

        $submissionId = $submitRes->json('submission.id');

        // 2. Admin grades the submission
        $gradeRes = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/assignments/submissions/{$submissionId}/grade", [
                'score' => 98,
                'feedback' => 'Outstanding architecture and complete test coverage!',
                'status' => 'graded',
            ]);

        $gradeRes->assertOk();
        $this->assertDatabaseHas('assignment_submissions', [
            'id' => $submissionId,
            'score' => 98,
            'status' => 'graded',
        ]);
    }

    public function test_unpublished_assignment_is_denied_to_enrolled_student(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $course = Course::create([
            'title' => 'Unpub Assign Course',
            'slug' => 'unpub-assign-course',
            'description' => 'Course with unpublished assignment',
            'instructor' => 'LE',
            'price' => 100,
            'duration' => '1 week',
            'difficulty' => 'Beginner',
        ]);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Unpub Assign Lesson',
            'type' => 'assignment',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $assignment = Assignment::create([
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'title' => 'Unpublished Assignment',
            'instructions' => 'Do not show',
            'max_marks' => 100,
            'is_published' => false,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/assignments/{$assignment->id}")
            ->assertStatus(403);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/assignments/{$assignment->id}/submit", [
                'submission_text' => 'Attempt on unpublished assignment.',
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('assignment_submissions', 0);
    }

    public function test_dropped_enrollment_student_cannot_submit_assignment(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $course = Course::create([
            'title' => 'Dropped Assign Course',
            'slug' => 'dropped-assign-course',
            'description' => 'Course with dropped enrollment',
            'instructor' => 'LE',
            'price' => 100,
            'duration' => '1 week',
            'difficulty' => 'Beginner',
        ]);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'dropped',
        ]);

        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Dropped Assign Lesson',
            'type' => 'assignment',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $assignment = Assignment::create([
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'title' => 'Dropped Assignment',
            'instructions' => 'Not for dropped students',
            'max_marks' => 100,
            'is_published' => true,
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/assignments/{$assignment->id}/submit", [
                'submission_text' => 'Should be blocked.',
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('assignment_submissions', 0);
    }
}
