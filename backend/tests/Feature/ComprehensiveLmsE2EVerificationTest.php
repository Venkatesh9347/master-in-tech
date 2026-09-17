<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseReview;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComprehensiveLmsE2EVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_lms_e2e_workflow(): void
    {
        // 1. Health & Core
        $healthRes = $this->getJson('/api/health');
        $healthRes->assertOk();

        // 2. Courses API & Listing
        $course = Course::create([
            'title' => 'Mastering Cloud & DevOps',
            'slug' => 'mastering-cloud-devops',
            'description' => 'Comprehensive masterclass on AWS, Kubernetes, Docker, and CI/CD pipelines.',
            'instructor' => 'Sarah Johnson',
            'price' => 24999,
            'duration' => '12 weeks',
            'difficulty' => 'Intermediate',
        ]);

        $coursesRes = $this->getJson('/api/courses');
        $coursesRes->assertOk();
        $coursesRes->assertJsonFragment(['title' => 'Mastering Cloud & DevOps']);

        // 3. Course Details (Public & Unauthenticated)
        $courseDetailRes = $this->getJson("/api/courses/{$course->id}");
        $courseDetailRes->assertOk();
        $courseDetailRes->assertJsonPath('is_enrolled', false);
        $courseDetailRes->assertJsonPath('title', 'Mastering Cloud & DevOps');

        // 4. Student Account Policy & Authentication (Goal 3A)
        $registerRes = $this->postJson('/api/register', [
            'name' => 'Alex Rivera',
            'email' => 'alex.rivera@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);
        $registerRes->assertForbidden();

        // Student account created by admin
        $student = User::factory()->create([
            'name' => 'Alex Rivera',
            'email' => 'alex.rivera@example.com',
            'password' => bcrypt('SecurePass123!'),
            'student_id' => 'STU-1001',
            'role' => 'student',
            'status' => 'active',
        ]);

        $loginRes = $this->postJson('/api/login', [
            'email' => 'alex.rivera@example.com',
            'password' => 'SecurePass123!',
        ]);
        $loginRes->assertOk();
        $token = $loginRes->json('access_token');
        $this->assertNotEmpty($token);

        // 5. Course Enrollment is admin-authorized; public self-enrollment is forbidden
        $selfEnrollRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/enroll");
        $selfEnrollRes->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $enrollRes = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/enrollments', [
                'user_id' => $student->id,
                'course_id' => $course->id,
                'status' => 'active',
                'override_reason' => 'E2E regression cohort admission approved by registrar.',
            ]);
        $enrollRes->assertCreated();
        $enrollRes->assertJsonPath('message', 'Course successfully assigned to student.');

        // Verify check endpoint
        $checkRes = $this->actingAs($student, 'sanctum')
            ->getJson("/api/courses/{$course->id}/enrollment");
        $checkRes->assertOk();
        $checkRes->assertJsonPath('enrolled', true);

        // 6. Student Dashboard (/api/my-courses)
        $myCoursesRes = $this->actingAs($student, 'sanctum')
            ->getJson('/api/my-courses');
        $myCoursesRes->assertOk();
        $myCoursesRes->assertJsonCount(1);
        $myCoursesRes->assertJsonPath('0.course_id', $course->id);

        // 7. Course Curriculum Structure Setup
        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1: Docker Essentials',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $videoLesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Containerizing Applications',
            'type' => 'video',
            'duration' => '18 min',
            'metadata' => ['video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $textLesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Docker Compose In Production',
            'type' => 'text',
            'duration' => '10 min',
            'metadata' => ['content' => 'Production guidelines for compose files.'],
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $quizLesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Docker Knowledge Check',
            'type' => 'quiz',
            'sort_order' => 2,
            'is_published' => true,
        ]);

        $quiz = Quiz::create([
            'lesson_id' => $quizLesson->id,
            'title' => 'Docker Knowledge Check',
            'passing_score' => 70,
            'is_published' => true,
        ]);

        $q1 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'What command starts containers in detached mode?',
            'type' => 'multiple_choice',
            'marks' => 10,
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $optCorrect = QuizOption::create([
            'question_id' => $q1->id,
            'option_text' => 'docker compose up -d',
            'is_correct' => true,
            'sort_order' => 0,
        ]);

        QuizOption::create([
            'question_id' => $q1->id,
            'option_text' => 'docker start -all',
            'is_correct' => false,
            'sort_order' => 1,
        ]);

        $assignmentLesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Containerize a Full-Stack App',
            'type' => 'assignment',
            'sort_order' => 3,
            'is_published' => true,
        ]);

        $assignment = Assignment::create([
            'lesson_id' => $assignmentLesson->id,
            'course_id' => $course->id,
            'title' => 'Containerize a Full-Stack App',
            'instructions' => 'Write Dockerfile and docker-compose.yml for frontend and backend.',
            'max_marks' => 100,
            'is_published' => true,
        ]);

        // 7. Lesson Access & LMS Progress
        $progressRes = $this->actingAs($student, 'sanctum')
            ->getJson("/api/courses/{$course->id}/lms-progress");
        $progressRes->assertOk();
        $progressRes->assertJsonPath('total_lessons', 4);
        $progressRes->assertJsonPath('completed_lesson_count', 0);

        // Complete Lesson 1 (Video)
        $complete1Res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/lessons/{$videoLesson->id}/complete");
        $complete1Res->assertOk();

        // Complete Lesson 2 (Text)
        $complete2Res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/lessons/{$textLesson->id}/complete");
        $complete2Res->assertOk();

        // 8. Quiz and Automatic Grading
        $quizDetailRes = $this->actingAs($student, 'sanctum')
            ->getJson("/api/quizzes/{$quiz->id}");
        $quizDetailRes->assertOk();

        $startQuizRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/quizzes/{$quiz->id}/start");
        $startQuizRes->assertCreated();
        $attemptId = $startQuizRes->json('attempt_id');

        $submitQuizRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/quiz-attempts/{$attemptId}/submit", [
                'answers' => [
                    ['question_id' => $q1->id, 'option_id' => $optCorrect->id],
                ],
            ]);
        $submitQuizRes->assertOk();
        $submitQuizRes->assertJsonPath('passed', true);
        $submitQuizRes->assertJsonPath('score', 10);
        $submitQuizRes->assertJsonPath('percentage', 100);

        // Mark quiz lesson as completed
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/lessons/{$quizLesson->id}/complete");

        // 9. Assignment Submission
        $assignmentDetailRes = $this->actingAs($student, 'sanctum')
            ->getJson("/api/assignments/{$assignment->id}");
        $assignmentDetailRes->assertOk();

        $submitAssignRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/assignments/{$assignment->id}/submit", [
                'submission_text' => 'Multi-stage Dockerfile configured with Nginx reverse proxy.',
                'file_url' => 'https://github.com/alexrivera/devops-capstone',
            ]);
        $submitAssignRes->assertCreated();
        $submissionId = $submitAssignRes->json('submission.id');

        // Mark assignment lesson as completed
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/lessons/{$assignmentLesson->id}/complete");

        // 10. Course Review & Rating
        $reviewRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/reviews", [
                'rating' => 5,
                'review_text' => 'Exceptional content and practical exercises!',
            ]);
        $reviewRes->assertCreated();

        $publicReviewsRes = $this->getJson("/api/courses/{$course->id}/reviews");
        $publicReviewsRes->assertOk();
        $publicReviewsRes->assertJsonPath('review_count', 1);
        $publicReviewsRes->assertJsonPath('average_rating', 5);

        // 11. Certificate Generation & Public Verification
        $certRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/certificate");
        $certRes->assertCreated();
        $certCode = $certRes->json('certificate.certificate_code');
        $this->assertNotEmpty($certCode);

        // Authenticated Certificate Lookup
        $certDetailRes = $this->actingAs($student, 'sanctum')
            ->getJson("/api/certificates/{$certCode}");
        $certDetailRes->assertOk();
        $certDetailRes->assertJsonPath('certificate_code', $certCode);

        // Public Certificate Verification (No auth required)
        $verifyRes = $this->getJson("/api/verify-certificate/{$certCode}");
        $verifyRes->assertOk();
        $verifyRes->assertJsonPath('valid', true);
        $verifyRes->assertJsonPath('recipient_name', 'Alex Rivera');
        $verifyRes->assertJsonPath('course_title', 'Mastering Cloud & DevOps');

        // 12. Admin Curriculum Management
        $admin = User::factory()->create(['role' => 'admin']);

        $newSectionRes = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/courses/{$course->id}/sections", [
                'title' => 'Module 2: Kubernetes Orchestration',
                'sort_order' => 1,
            ]);
        $newSectionRes->assertCreated();
        $sec2Id = $newSectionRes->json('id');

        $newLessonRes = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/courses/{$course->id}/sections/{$sec2Id}/lessons", [
                'title' => 'Kubernetes Pods & Deployments',
                'type' => 'video',
                'duration' => '25 min',
                'sort_order' => 0,
            ]);
        $newLessonRes->assertCreated();

        // 13. Admin Assignment Grading Desk
        $adminSubmissionsRes = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/assignments/submissions');
        $adminSubmissionsRes->assertOk();
        $adminSubmissionsRes->assertJsonCount(1, 'data');

        $gradeRes = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/assignments/submissions/{$submissionId}/grade", [
                'score' => 96.5,
                'feedback' => 'Flawless multi-stage build setup and clean documentation.',
                'status' => 'graded',
            ]);
        $gradeRes->assertOk();
        $gradeRes->assertJsonPath('submission.score', '96.50');
        $gradeRes->assertJsonPath('submission.status', 'graded');

        // 14. Events Page & API
        $event = Event::create([
            'title' => 'AI Engineering Masterclass 2026',
            'slug' => 'ai-engineering-masterclass-2026',
            'description' => 'Live hands-on workshop on generative AI agents and LLM architectures.',
            'speaker_name' => 'Dr. Andrew Chen',
            'speaker_designation' => 'Principal AI Researcher',
            'event_date' => now()->addDays(5)->format('Y-m-d H:i:s'),
            'start_time' => '10:00',
            'end_time' => '13:00',
            'duration' => 180,
            'mode' => 'online',
            'meeting_link' => 'https://meet.google.com/mit-ai-masterclass',
            'price' => 0,
            'status' => 'published',
            'total_seats' => 100,
        ]);

        $eventsRes = $this->getJson('/api/events');
        $eventsRes->assertOk();
        $eventsRes->assertJsonFragment(['title' => 'AI Engineering Masterclass 2026']);

        $eventDetailRes = $this->getJson("/api/events/{$event->id}");
        $eventDetailRes->assertOk();
        $eventDetailRes->assertJsonPath('title', 'AI Engineering Masterclass 2026');

        // Student Event Registration
        $eventRegRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/events/{$event->id}/register");
        $eventRegRes->assertCreated();

        // Student Registered Events Hub
        $myEventsRes = $this->actingAs($student, 'sanctum')
            ->getJson('/api/my-events');
        $myEventsRes->assertOk();
        $myEventsRes->assertJsonCount(1);
    }
}
