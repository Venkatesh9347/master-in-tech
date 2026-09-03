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
}
