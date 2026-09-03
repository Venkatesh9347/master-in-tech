<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseContentManagementTest extends TestCase
{
    use RefreshDatabase;

    private function createTutorAndCourse(): array
    {
        $tutor = User::factory()->create(['role' => 'tutor']);
        $course = Course::create([
            'title' => 'Cloud Systems Engineering Masterclass',
            'slug' => 'cloud-systems-engineering-masterclass',
            'description' => 'Architect resilient multi-region cloud infrastructures.',
            'category' => 'Cloud',
            'instructor' => $tutor->name,
            'instructor_id' => $tutor->id,
            'duration' => '12 Weeks',
            'difficulty' => 'Advanced',
            'is_published' => true,
        ]);

        return [$tutor, $course];
    }

    // 1. Tutor can create module for owned course
    public function test_01_tutor_can_create_module_for_owned_course(): void
    {
        [$tutor, $course] = $this->createTutorAndCourse();
        Sanctum::actingAs($tutor);

        $response = $this->postJson("/api/courses/{$course->id}/sections", [
            'title' => 'Module 1: VPC Architecture & Subnets',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('title', 'Module 1: VPC Architecture & Subnets');

        $this->assertDatabaseHas('sections', [
            'course_id' => $course->id,
            'title' => 'Module 1: VPC Architecture & Subnets',
            'sort_order' => 1,
            'is_published' => true,
        ]);
    }

    // 2. Tutor cannot create module for another tutor's course
    public function test_02_tutor_cannot_create_module_for_another_tutors_course(): void
    {
        [$tutorA, $courseA] = $this->createTutorAndCourse();
        $tutorB = User::factory()->create(['role' => 'tutor']);

        Sanctum::actingAs($tutorB);

        $response = $this->postJson("/api/courses/{$courseA->id}/sections", [
            'title' => 'Hacked Module',
            'sort_order' => 1,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('sections', [
            'course_id' => $courseA->id,
            'title' => 'Hacked Module',
        ]);
    }

    // 3. Tutor can create lesson inside module
    public function test_03_tutor_can_create_lesson(): void
    {
        [$tutor, $course] = $this->createTutorAndCourse();
        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1: Foundations',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        Sanctum::actingAs($tutor);

        $response = $this->postJson("/api/courses/{$course->id}/sections/{$section->id}/lessons", [
            'title' => 'Deep Dive Video Lesson',
            'type' => 'video',
            'duration' => '25 min',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'content' => 'Overview of VPC networking guidelines and CIDR calculation.',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('title', 'Deep Dive Video Lesson')
            ->assertJsonPath('type', 'video');

        $this->assertDatabaseHas('lessons', [
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Deep Dive Video Lesson',
            'type' => 'video',
            'is_published' => true,
        ]);
    }

    // 4. Tutor cannot modify another tutor's lesson
    public function test_04_tutor_cannot_modify_another_tutors_lesson(): void
    {
        [$tutorA, $courseA] = $this->createTutorAndCourse();
        $sectionA = Section::create([
            'course_id' => $courseA->id,
            'title' => 'Module 1',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $lessonA = Lesson::create([
            'course_id' => $courseA->id,
            'section_id' => $sectionA->id,
            'title' => 'Original Lesson',
            'type' => 'text',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $tutorB = User::factory()->create(['role' => 'tutor']);
        Sanctum::actingAs($tutorB);

        $response = $this->putJson("/api/courses/{$courseA->id}/sections/{$sectionA->id}/lessons/{$lessonA->id}", [
            'title' => 'Unauthorized Modified Title',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('lessons', [
            'id' => $lessonA->id,
            'title' => 'Unauthorized Modified Title',
        ]);
    }

    // 5. Student sees published lessons
    public function test_05_student_sees_published_lessons(): void
    {
        [$tutor, $course] = $this->createTutorAndCourse();
        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1: Public Module',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Published Lesson 1',
            'type' => 'text',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $student = User::factory()->create(['role' => 'student']);
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson("/api/courses/{$course->id}/sections");
        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.title', 'Module 1: Public Module')
            ->assertJsonPath('0.lessons.0.title', 'Published Lesson 1');
    }

    // 6. Student cannot see draft lessons
    public function test_06_student_cannot_see_draft_lessons(): void
    {
        [$tutor, $course] = $this->createTutorAndCourse();
        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Published Lesson',
            'type' => 'text',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Top Secret Draft Lesson',
            'type' => 'text',
            'sort_order' => 2,
            'is_published' => false,
        ]);

        $student = User::factory()->create(['role' => 'student']);
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson("/api/courses/{$course->id}/sections");
        $response->assertOk();

        $lessons = $response->json('0.lessons');
        $this->assertCount(1, $lessons);
        $this->assertEquals('Published Lesson', $lessons[0]['title']);
    }

    // 7. Student cannot access unpublished lesson directly
    public function test_07_student_cannot_access_unpublished_lesson_directly(): void
    {
        [$tutor, $course] = $this->createTutorAndCourse();
        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $draftLesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Draft Lesson',
            'type' => 'text',
            'sort_order' => 1,
            'is_published' => false,
        ]);

        $student = User::factory()->create(['role' => 'student']);
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);

        // Direct show endpoint must be 403 Forbidden
        $this->getJson("/api/courses/{$course->id}/sections/{$section->id}/lessons/{$draftLesson->id}")
            ->assertStatus(403);
    }

    // 8. Quiz answers are not exposed before submission
    public function test_08_quiz_answers_are_not_exposed_before_submission(): void
    {
        [$tutor, $course] = $this->createTutorAndCourse();
        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $quizLesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Quiz Lesson',
            'type' => 'quiz',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $quiz = Quiz::create([
            'lesson_id' => $quizLesson->id,
            'title' => 'Security Quiz',
            'passing_score' => 75,
            'is_published' => true,
        ]);
        $q = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'Is HTTPS encrypted via TLS?',
            'type' => 'multiple_choice',
            'marks' => 10,
            'sort_order' => 1,
        ]);
        QuizOption::create(['question_id' => $q->id, 'option_text' => 'True', 'is_correct' => true]);
        QuizOption::create(['question_id' => $q->id, 'option_text' => 'False', 'is_correct' => false]);

        $student = User::factory()->create(['role' => 'student']);
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson("/api/quizzes/{$quiz->id}");
        $response->assertOk();

        $options = $response->json('questions.0.options');
        foreach ($options as $opt) {
            $this->assertArrayNotHasKey('is_correct', $opt, 'Security violation: is_correct exposed to student.');
        }
    }

    // 9. Quiz score is calculated server-side
    public function test_09_quiz_score_is_calculated_server_side(): void
    {
        [$tutor, $course] = $this->createTutorAndCourse();
        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $quizLesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Quiz Lesson',
            'type' => 'quiz',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $quiz = Quiz::create([
            'lesson_id' => $quizLesson->id,
            'title' => 'Security Quiz',
            'passing_score' => 70,
            'is_published' => true,
        ]);
        $q = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'What is the default HTTPS port?',
            'type' => 'multiple_choice',
            'marks' => 20,
            'sort_order' => 1,
        ]);
        $optCorrect = QuizOption::create(['question_id' => $q->id, 'option_text' => '443', 'is_correct' => true]);
        QuizOption::create(['question_id' => $q->id, 'option_text' => '80', 'is_correct' => false]);

        $student = User::factory()->create(['role' => 'student']);
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);

        $startRes = $this->postJson("/api/quizzes/{$quiz->id}/start");
        $attemptId = $startRes->json('attempt_id');

        $submitRes = $this->postJson("/api/quiz-attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $q->id, 'option_id' => $optCorrect->id],
            ],
        ]);

        $submitRes->assertOk()
            ->assertJsonPath('score', 20)
            ->assertJsonPath('percentage', 100)
            ->assertJsonPath('passed', true);
    }

    // 10. Assignment submission belongs to authenticated student
    public function test_10_assignment_submission_belongs_to_authenticated_student(): void
    {
        [$tutor, $course] = $this->createTutorAndCourse();
        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $assignLesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Terraform Capstone',
            'type' => 'assignment',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $assignment = Assignment::create([
            'lesson_id' => $assignLesson->id,
            'course_id' => $course->id,
            'title' => 'Terraform VPC Capstone',
            'instructions' => 'Deploy high-availability VPC',
            'max_marks' => 100,
            'is_published' => true,
        ]);

        $student = User::factory()->create(['role' => 'student']);
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);

        $response = $this->postJson("/api/assignments/{$assignment->id}/submit", [
            'submission_text' => 'https://github.com/student/terraform-vpc',
            'file_url' => 'https://storage.example.com/arch.pdf',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('submission.user_id', $student->id);

        $this->assertDatabaseHas('assignment_submissions', [
            'assignment_id' => $assignment->id,
            'user_id' => $student->id,
            'status' => 'submitted',
        ]);
    }

    // 11. Student cannot access another student's submission
    public function test_11_student_cannot_access_another_students_submission(): void
    {
        [$tutor, $course] = $this->createTutorAndCourse();
        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $assignLesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Capstone',
            'type' => 'assignment',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $assignment = Assignment::create([
            'lesson_id' => $assignLesson->id,
            'course_id' => $course->id,
            'title' => 'Capstone',
            'instructions' => 'Deploy',
            'max_marks' => 100,
            'is_published' => true,
        ]);

        $studentA = User::factory()->create(['role' => 'student']);
        CourseEnrollment::create([
            'user_id' => $studentA->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);
        AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'lesson_id' => $assignLesson->id,
            'course_id' => $course->id,
            'user_id' => $studentA->id,
            'submission_text' => 'Confidential architecture proposal',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $studentB = User::factory()->create(['role' => 'student']);
        CourseEnrollment::create([
            'user_id' => $studentB->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);
        Sanctum::actingAs($studentB);

        $response = $this->getJson("/api/assignments/{$assignment->id}");
        $response->assertOk();
        $this->assertNull($response->json('submission'), 'Student B must not receive Student A\'s submission.');

        // Student C not enrolled in course must receive 403 Forbidden
        $studentC = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($studentC);
        $this->getJson("/api/assignments/{$assignment->id}")
            ->assertStatus(403);
    }

    // 12. Reordering persists correctly in database
    public function test_12_reordering_persists_correctly(): void
    {
        [$tutor, $course] = $this->createTutorAndCourse();
        $sec1 = Section::create(['course_id' => $course->id, 'title' => 'Section 1', 'sort_order' => 1, 'is_published' => true]);
        $sec2 = Section::create(['course_id' => $course->id, 'title' => 'Section 2', 'sort_order' => 2, 'is_published' => true]);

        $les1 = Lesson::create(['course_id' => $course->id, 'section_id' => $sec1->id, 'title' => 'Lesson 1', 'sort_order' => 1, 'type' => 'text']);
        $les2 = Lesson::create(['course_id' => $course->id, 'section_id' => $sec1->id, 'title' => 'Lesson 2', 'sort_order' => 2, 'type' => 'text']);

        Sanctum::actingAs($tutor);

        // Swap sections and swap lessons
        $response = $this->postJson("/api/courses/{$course->id}/reorder", [
            'sections' => [
                ['id' => $sec1->id, 'sort_order' => 2],
                ['id' => $sec2->id, 'sort_order' => 1],
            ],
            'lessons' => [
                ['id' => $les1->id, 'sort_order' => 2],
                ['id' => $les2->id, 'sort_order' => 1],
            ],
        ]);

        $response->assertOk();

        $this->assertEquals(2, $sec1->fresh()->sort_order);
        $this->assertEquals(1, $sec2->fresh()->sort_order);
        $this->assertEquals(2, $les1->fresh()->sort_order);
        $this->assertEquals(1, $les2->fresh()->sort_order);
    }

    // 13. Deleting content with student progress is safely blocked
    public function test_13_deleting_content_with_student_progress_is_safely_blocked(): void
    {
        [$tutor, $course] = $this->createTutorAndCourse();
        $sec = Section::create(['course_id' => $course->id, 'title' => 'Section 1', 'sort_order' => 1, 'is_published' => true]);
        $les = Lesson::create(['course_id' => $course->id, 'section_id' => $sec->id, 'title' => 'Lesson 1', 'sort_order' => 1, 'type' => 'text']);

        $student = User::factory()->create(['role' => 'student']);
        LessonProgress::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $les->id,
            'section_id' => $sec->id,
            'started' => true,
            'completed' => true,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($tutor);

        // Deleting the lesson directly must return 422 delete blocked
        $delLessonRes = $this->deleteJson("/api/courses/{$course->id}/sections/{$sec->id}/lessons/{$les->id}");
        $delLessonRes->assertStatus(422)
            ->assertJsonPath('delete_blocked', true);

        $this->assertDatabaseHas('lessons', ['id' => $les->id]);

        // Deleting the section must also return 422 delete blocked
        $delSecRes = $this->deleteJson("/api/courses/{$course->id}/sections/{$sec->id}");
        $delSecRes->assertStatus(422)
            ->assertJsonPath('delete_blocked', true);

        $this->assertDatabaseHas('sections', ['id' => $sec->id]);
    }

    // 14. Admin can inspect course content
    public function test_14_admin_can_inspect_course_content(): void
    {
        [$tutor, $course] = $this->createTutorAndCourse();
        $sec = Section::create(['course_id' => $course->id, 'title' => 'Module 1', 'sort_order' => 1, 'is_published' => true]);
        Lesson::create(['course_id' => $course->id, 'section_id' => $sec->id, 'title' => 'Draft Lesson', 'sort_order' => 1, 'type' => 'text', 'is_published' => false]);
        Lesson::create(['course_id' => $course->id, 'section_id' => $sec->id, 'title' => 'Published Lesson', 'sort_order' => 2, 'type' => 'text', 'is_published' => true]);

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/courses/{$course->id}/sections");
        $response->assertOk()
            ->assertJsonCount(1);

        $lessons = $response->json('0.lessons');
        $this->assertCount(2, $lessons, 'Admin should see both draft and published lessons.');
    }

    // 15. Unauthorized users receive proper authorization responses
    public function test_15_unauthorized_users_receive_proper_authorization_responses(): void
    {
        [$tutor, $course] = $this->createTutorAndCourse();

        // Unauthenticated
        $this->postJson("/api/courses/{$course->id}/sections", ['title' => 'Test'])
            ->assertStatus(401);

        // Student attempting to create section
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        $this->postJson("/api/courses/{$course->id}/sections", ['title' => 'Test'])
            ->assertStatus(403);
    }
}
