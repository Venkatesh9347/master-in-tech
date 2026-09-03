<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LearningActivityLog;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentLearningAndProgressTest extends TestCase
{
    use RefreshDatabase;

    private function createCourseWithCurriculum(?User $instructor = null): array
    {
        $instructor = $instructor ?? User::factory()->create(['role' => 'tutor']);

        $course = Course::create([
            'title' => 'Advanced Cloud Architecture',
            'slug' => 'adv-cloud-arch-' . uniqid(),
            'description' => 'Enterprise cloud microservices',
            'instructor' => $instructor->name,
            'instructor_id' => $instructor->id,
            'duration' => '10 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ]);

        $section1 = Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1: Cloud Core',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $lesson1 = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section1->id,
            'title' => '1.1 VPC Architecture',
            'type' => 'video',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $lesson2 = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section1->id,
            'title' => '1.2 Subnet Routing',
            'type' => 'text',
            'sort_order' => 2,
            'is_published' => true,
        ]);

        $lesson3Unpublished = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section1->id,
            'title' => '1.3 Internal Draft Lesson',
            'type' => 'video',
            'sort_order' => 3,
            'is_published' => false,
        ]);

        return [$course, $section1, $lesson1, $lesson2, $lesson3Unpublished, $instructor];
    }

    // 1. Student starts lesson
    public function test_1_student_starts_lesson(): void
    {
        [$course, $section1, $lesson1] = $this->createCourseWithCurriculum();
        $student = User::factory()->create(['role' => 'student']);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);
        $res = $this->postJson("/api/courses/{$course->id}/lessons/{$lesson1->id}/start");

        $res->assertOk()
            ->assertJsonPath('started', true)
            ->assertJsonPath('lesson_id', $lesson1->id);
    }

    // 2. Started timestamp is persisted
    public function test_2_started_timestamp_is_persisted(): void
    {
        [$course, $section1, $lesson1] = $this->createCourseWithCurriculum();
        $student = User::factory()->create(['role' => 'student']);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);
        $this->getJson("/api/courses/{$course->id}/sections/{$section1->id}/lessons/{$lesson1->id}")->assertOk();

        $progress = LessonProgress::where('user_id', $student->id)
            ->where('lesson_id', $lesson1->id)
            ->first();

        $this->assertNotNull($progress);
        $this->assertTrue($progress->started);
        $this->assertNotNull($progress->started_at);
    }

    // 3. Last accessed timestamp is persisted
    public function test_3_last_accessed_timestamp_is_persisted(): void
    {
        [$course, $section1, $lesson1] = $this->createCourseWithCurriculum();
        $student = User::factory()->create(['role' => 'student']);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);
        $this->postJson("/api/courses/{$course->id}/lessons/{$lesson1->id}/start")->assertOk();

        $progress = LessonProgress::where('user_id', $student->id)
            ->where('lesson_id', $lesson1->id)
            ->first();

        $this->assertNotNull($progress->last_accessed_at);
    }

    // 4. Student cannot complete another student's lesson
    public function test_4_student_cannot_complete_another_students_lesson(): void
    {
        [$course, $section1, $lesson1] = $this->createCourseWithCurriculum();
        $studentA = User::factory()->create(['role' => 'student']);
        $studentB = User::factory()->create(['role' => 'student']);

        CourseEnrollment::create([
            'user_id' => $studentA->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($studentA);
        // Student A completes lesson 2 (text)
        $this->postJson("/api/courses/{$course->id}/lessons/{$lesson1->id}/complete")->assertOk();

        // Student B's progress must remain unaffected in the database
        $this->assertDatabaseMissing('lesson_progress', [
            'user_id' => $studentB->id,
            'lesson_id' => $lesson1->id,
            'completed' => true,
        ]);
    }

    // 5. Student cannot complete an unpublished lesson
    public function test_5_student_cannot_complete_an_unpublished_lesson(): void
    {
        [$course, $section1, $lesson1, $lesson2, $lesson3Unpublished] = $this->createCourseWithCurriculum();
        $student = User::factory()->create(['role' => 'student']);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);
        $res = $this->postJson("/api/courses/{$course->id}/lessons/{$lesson3Unpublished->id}/complete");

        $res->assertStatus(403);
    }

    // 6. Student cannot complete a lesson from another course
    public function test_6_student_cannot_complete_a_lesson_from_another_course(): void
    {
        [$courseA, $secA, $lessonA] = $this->createCourseWithCurriculum();
        [$courseB, $secB, $lessonB] = $this->createCourseWithCurriculum();

        $student = User::factory()->create(['role' => 'student']);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $courseA->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);
        // Attempting to complete lessonB using courseA endpoint
        $res = $this->postJson("/api/courses/{$courseA->id}/lessons/{$lessonB->id}/complete");

        $res->assertStatus(404);
    }

    // 7. Student must be enrolled
    public function test_7_student_must_be_enrolled(): void
    {
        [$course, $section1, $lesson1] = $this->createCourseWithCurriculum();
        $student = User::factory()->create(['role' => 'student']);

        Sanctum::actingAs($student);
        $res = $this->postJson("/api/courses/{$course->id}/lessons/{$lesson1->id}/complete");

        $res->assertStatus(403)
            ->assertJsonPath('enrollment_required', true);
    }

    // 8. Duplicate progress record cannot be created (unique constraint)
    public function test_8_duplicate_progress_record_cannot_be_created(): void
    {
        [$course, $section1, $lesson1, $lesson2] = $this->createCourseWithCurriculum();
        $student = User::factory()->create(['role' => 'student']);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);
        $this->postJson("/api/courses/{$course->id}/lessons/{$lesson2->id}/complete")->assertOk();
        $this->postJson("/api/courses/{$course->id}/lessons/{$lesson2->id}/complete")->assertOk();
        $this->postJson("/api/courses/{$course->id}/lessons/{$lesson2->id}/complete")->assertOk();

        $count = LessonProgress::where('user_id', $student->id)
            ->where('lesson_id', $lesson2->id)
            ->count();

        $this->assertEquals(1, $count);
    }

    // 9. Completion is persisted
    public function test_9_completion_is_persisted(): void
    {
        [$course, $section1, $lesson1, $lesson2] = $this->createCourseWithCurriculum();
        $student = User::factory()->create(['role' => 'student']);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);
        $res = $this->postJson("/api/courses/{$course->id}/lessons/{$lesson2->id}/complete");

        $res->assertOk()->assertJsonPath('completed', true);

        $this->assertDatabaseHas('lesson_progress', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson2->id,
            'completed' => true,
            'status' => 'completed',
        ]);
    }

    // 10. Failed completion does not modify progress
    public function test_10_failed_completion_does_not_modify_progress(): void
    {
        [$course, $section1, $lesson1, $lesson2] = $this->createCourseWithCurriculum();
        $nonEnrolledStudent = User::factory()->create(['role' => 'student']);

        Sanctum::actingAs($nonEnrolledStudent);
        $res = $this->postJson("/api/courses/{$course->id}/lessons/{$lesson2->id}/complete");

        $res->assertStatus(403);
        $this->assertDatabaseMissing('lesson_progress', [
            'user_id' => $nonEnrolledStudent->id,
            'lesson_id' => $lesson2->id,
        ]);
    }

    // 11. Course progress is calculated correctly
    public function test_11_course_progress_is_calculated_correctly(): void
    {
        [$course, $section1, $lesson1, $lesson2, $lesson3Unpublished] = $this->createCourseWithCurriculum();
        $student = User::factory()->create(['role' => 'student']);

        $enrollment = CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);
        // Complete 1 of 2 published lessons
        $this->postJson("/api/courses/{$course->id}/lessons/{$lesson2->id}/complete")->assertOk();

        $res = $this->getJson("/api/courses/{$course->id}/lms-progress");
        $res->assertOk()
            ->assertJsonPath('total_lessons', 2)
            ->assertJsonPath('completed_lesson_count', 1)
            ->assertJsonPath('progress_percentage', 50)
            ->assertJsonPath('is_course_completed', false);

        $this->assertEquals(50.0, (float) $enrollment->fresh()->progress_percentage);
    }

    // 12. Continue Learning returns correct lesson
    public function test_12_continue_learning_returns_correct_lesson(): void
    {
        [$course, $section1, $lesson1, $lesson2] = $this->createCourseWithCurriculum();
        $student = User::factory()->create(['role' => 'student']);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);

        // Before any lesson access: returns lesson 1 (first incomplete)
        $res = $this->getJson("/api/courses/{$course->id}/lms-progress");
        $res->assertOk()
            ->assertJsonPath('current_lesson.id', $lesson1->id);

        // Student opens lesson 2 and completes lesson 1
        $this->postJson("/api/courses/{$course->id}/lessons/{$lesson2->id}/start")->assertOk();
        $this->postJson("/api/courses/{$course->id}/lessons/{$lesson1->id}/complete")->assertOk();

        // Returns lesson 2 (last accessed incomplete)
        $res2 = $this->getJson("/api/courses/{$course->id}/lms-progress");
        $res2->assertOk()
            ->assertJsonPath('current_lesson.id', $lesson2->id);
    }

    // 13. Quiz score is calculated by backend
    public function test_13_quiz_score_is_calculated_by_backend(): void
    {
        [$course, $section1] = $this->createCourseWithCurriculum();
        $quizLesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section1->id,
            'title' => 'Cloud VPC Quiz',
            'type' => 'quiz',
            'sort_order' => 10,
            'is_published' => true,
        ]);

        $quiz = Quiz::create([
            'lesson_id' => $quizLesson->id,
            'title' => 'VPC Security Quiz',
            'passing_score' => 70,
            'is_published' => true,
        ]);

        $q1 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'What does CIDR stand for?',
            'type' => 'multiple_choice',
            'marks' => 10,
            'sort_order' => 1,
        ]);

        $optCorrect = QuizOption::create([
            'question_id' => $q1->id,
            'option_text' => 'Classless Inter-Domain Routing',
            'is_correct' => true,
        ]);

        $optWrong = QuizOption::create([
            'question_id' => $q1->id,
            'option_text' => 'Classified Internal Domain Router',
            'is_correct' => false,
        ]);

        $student = User::factory()->create(['role' => 'student']);
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);

        // Start quiz attempt
        $startRes = $this->postJson("/api/quizzes/{$quiz->id}/start");
        $startRes->assertCreated();
        $attemptId = $startRes->json('attempt_id');

        // Submit correct answer
        $submitRes = $this->postJson("/api/quiz-attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $q1->id, 'option_id' => $optCorrect->id],
            ],
        ]);

        $submitRes->assertOk()
            ->assertJsonPath('score', 10)
            ->assertJsonPath('total_marks', 10)
            ->assertJsonPath('percentage', 100)
            ->assertJsonPath('passed', true);
    }

    // 14. Failed quiz cannot complete the lesson
    public function test_14_failed_quiz_cannot_complete_the_lesson(): void
    {
        [$course, $section1] = $this->createCourseWithCurriculum();
        $quizLesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section1->id,
            'title' => 'Cloud VPC Quiz',
            'type' => 'quiz',
            'sort_order' => 10,
            'is_published' => true,
        ]);

        $quiz = Quiz::create([
            'lesson_id' => $quizLesson->id,
            'title' => 'VPC Security Quiz',
            'passing_score' => 70,
            'is_published' => true,
        ]);

        $q1 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'What does CIDR stand for?',
            'type' => 'multiple_choice',
            'marks' => 10,
            'sort_order' => 1,
        ]);

        QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Correct Answer', 'is_correct' => true]);
        $optWrong = QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Wrong Answer', 'is_correct' => false]);

        $student = User::factory()->create(['role' => 'student']);
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);

        // Start and submit wrong answer
        $startRes = $this->postJson("/api/quizzes/{$quiz->id}/start");
        $attemptId = $startRes->json('attempt_id');

        $this->postJson("/api/quiz-attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $q1->id, 'option_id' => $optWrong->id],
            ],
        ])->assertOk()->assertJsonPath('passed', false);

        // Attempting to directly complete the quiz lesson must be rejected (422)
        $completeRes = $this->postJson("/api/courses/{$course->id}/lessons/{$quizLesson->id}/complete");
        $completeRes->assertStatus(422)
            ->assertJsonPath('requirement_unmet', 'quiz_not_passed');

        $this->assertDatabaseMissing('lesson_progress', [
            'user_id' => $student->id,
            'lesson_id' => $quizLesson->id,
            'completed' => true,
        ]);
    }

    // 15. Passing quiz can complete the lesson
    public function test_15_passing_quiz_can_complete_the_lesson(): void
    {
        [$course, $section1] = $this->createCourseWithCurriculum();
        $quizLesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section1->id,
            'title' => 'Cloud VPC Quiz',
            'type' => 'quiz',
            'sort_order' => 10,
            'is_published' => true,
        ]);

        $quiz = Quiz::create([
            'lesson_id' => $quizLesson->id,
            'title' => 'VPC Security Quiz',
            'passing_score' => 70,
            'is_published' => true,
        ]);

        $q1 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'What does CIDR stand for?',
            'type' => 'multiple_choice',
            'marks' => 10,
            'sort_order' => 1,
        ]);

        $optCorrect = QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Correct', 'is_correct' => true]);

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

        $this->postJson("/api/quiz-attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $q1->id, 'option_id' => $optCorrect->id],
            ],
        ])->assertOk()->assertJsonPath('passed', true);

        // Lesson progress must be completed in DB
        $this->assertDatabaseHas('lesson_progress', [
            'user_id' => $student->id,
            'lesson_id' => $quizLesson->id,
            'completed' => true,
            'status' => 'completed',
        ]);
    }

    // 16. Assignment submission is persisted
    public function test_16_assignment_submission_is_persisted(): void
    {
        [$course, $section1] = $this->createCourseWithCurriculum();
        $assignLesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section1->id,
            'title' => 'VPC Routing Capstone',
            'type' => 'assignment',
            'sort_order' => 12,
            'is_published' => true,
        ]);

        $assignment = Assignment::create([
            'lesson_id' => $assignLesson->id,
            'course_id' => $course->id,
            'title' => 'Terraform VPC Deployment',
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

        $res = $this->postJson("/api/assignments/{$assignment->id}/submit", [
            'submission_text' => 'https://github.com/student/terraform-vpc',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('message', 'Assignment submitted successfully.');

        $this->assertDatabaseHas('assignment_submissions', [
            'user_id' => $student->id,
            'assignment_id' => $assignment->id,
            'submission_text' => 'https://github.com/student/terraform-vpc',
            'status' => 'submitted',
        ]);
    }

    // 17. Assignment requiring grading cannot be auto-completed until graded
    public function test_17_assignment_requiring_grading_cannot_be_auto_completed(): void
    {
        [$course, $section1, $l1, $l2, $l3, $tutor] = $this->createCourseWithCurriculum();
        $assignLesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section1->id,
            'title' => 'VPC Routing Capstone',
            'type' => 'assignment',
            'sort_order' => 12,
            'is_published' => true,
        ]);

        $assignment = Assignment::create([
            'lesson_id' => $assignLesson->id,
            'course_id' => $course->id,
            'title' => 'Terraform VPC Deployment',
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

        // Student has NOT submitted assignment
        Sanctum::actingAs($student);
        $resNoSub = $this->postJson("/api/courses/{$course->id}/lessons/{$assignLesson->id}/complete");
        $resNoSub->assertStatus(422)
            ->assertJsonPath('requirement_unmet', 'assignment_not_submitted');

        // Student submits assignment
        $this->postJson("/api/assignments/{$assignment->id}/submit", [
            'submission_text' => 'My assignment submission',
        ])->assertStatus(201);

        $sub = AssignmentSubmission::where('user_id', $student->id)->where('assignment_id', $assignment->id)->first();

        // Tutor grades assignment with passing score 85/100
        Sanctum::actingAs($tutor);
        $gradeRes = $this->postJson("/api/tutor/submissions/{$sub->id}/grade", [
            'score' => 85,
            'feedback' => 'Excellent VPC architecture design!',
            'status' => 'graded',
        ]);

        $gradeRes->assertOk();

        // Lesson progress must now be completed
        $this->assertDatabaseHas('lesson_progress', [
            'user_id' => $student->id,
            'lesson_id' => $assignLesson->id,
            'completed' => true,
            'status' => 'completed',
        ]);
    }

    // 18. Concurrent and duplicate completion requests remain consistent
    public function test_18_concurrent_completion_requests_remain_consistent(): void
    {
        [$course, $section1, $lesson1, $lesson2] = $this->createCourseWithCurriculum();
        $student = User::factory()->create(['role' => 'student']);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);

        // Multiple rapid completion calls
        $r1 = $this->postJson("/api/courses/{$course->id}/lessons/{$lesson2->id}/complete");
        $r2 = $this->postJson("/api/courses/{$course->id}/lessons/{$lesson2->id}/complete");
        $r3 = $this->postJson("/api/courses/{$course->id}/lessons/{$lesson2->id}/complete");

        $r1->assertOk();
        $r2->assertOk();
        $r3->assertOk();

        $this->assertEquals(1, LessonProgress::where('user_id', $student->id)->where('lesson_id', $lesson2->id)->count());
    }

    // 19. Student can refresh and receive the same database state
    public function test_19_student_can_refresh_and_receive_same_database_state(): void
    {
        [$course, $section1, $lesson1, $lesson2] = $this->createCourseWithCurriculum();
        $student = User::factory()->create(['role' => 'student']);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($student);
        $this->postJson("/api/courses/{$course->id}/lessons/{$lesson2->id}/complete")->assertOk();

        // Simulate subsequent page loads
        $firstFetch = $this->getJson("/api/courses/{$course->id}/lms-progress")->json();
        $secondFetch = $this->getJson("/api/courses/{$course->id}/lms-progress")->json();

        $this->assertEquals($firstFetch['progress_percentage'], $secondFetch['progress_percentage']);
        $this->assertEquals($firstFetch['completed_lessons'], $secondFetch['completed_lessons']);
        $this->assertEquals($firstFetch['completed_lesson_count'], $secondFetch['completed_lesson_count']);
    }

    // 20. Student cannot access another student's progress
    public function test_20_student_cannot_access_another_students_progress(): void
    {
        [$course, $section1, $lesson1, $lesson2] = $this->createCourseWithCurriculum();
        $studentA = User::factory()->create(['role' => 'student']);
        $studentB = User::factory()->create(['role' => 'student']);

        CourseEnrollment::create(['user_id' => $studentA->id, 'course_id' => $course->id, 'status' => 'active', 'enrolled_at' => now()]);
        CourseEnrollment::create(['user_id' => $studentB->id, 'course_id' => $course->id, 'status' => 'active', 'enrolled_at' => now()]);

        Sanctum::actingAs($studentA);
        $this->postJson("/api/courses/{$course->id}/lessons/{$lesson2->id}/complete")->assertOk();

        Sanctum::actingAs($studentB);
        $resB = $this->getJson("/api/courses/{$course->id}/lms-progress");
        $resB->assertOk()
            ->assertJsonPath('completed_lesson_count', 0)
            ->assertJsonPath('completed_lessons', []);
    }

    // 21. Tutor authorization works
    public function test_21_tutor_authorization_works(): void
    {
        [$course, $section1, $lesson1, $lesson2, $l3, $tutor] = $this->createCourseWithCurriculum();
        $otherTutor = User::factory()->create(['role' => 'tutor']);
        $student = User::factory()->create(['role' => 'student']);

        CourseEnrollment::create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active', 'enrolled_at' => now()]);

        // Course instructor can view course students
        Sanctum::actingAs($tutor);
        $resTutor = $this->getJson("/api/tutor/courses/{$course->id}/students");
        $resTutor->assertOk();

        // Other tutor cannot inspect another instructor's course students (403)
        Sanctum::actingAs($otherTutor);
        $resOther = $this->getJson("/api/tutor/courses/{$course->id}/students");
        $resOther->assertStatus(403);
    }

    // 22. Admin authorization works
    public function test_22_admin_authorization_works(): void
    {
        [$course, $section1, $lesson1, $lesson2] = $this->createCourseWithCurriculum();
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);

        CourseEnrollment::create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active', 'enrolled_at' => now()]);

        Sanctum::actingAs($admin);

        // Admin can inspect student progress across courses
        $resProgress = $this->getJson("/api/admin/students/{$student->id}/progress");
        $resProgress->assertOk()
            ->assertJsonPath('user.id', $student->id);

        // Admin can inspect learning audit logs
        $resLogs = $this->getJson("/api/admin/activity-logs");
        $resLogs->assertOk();
    }
}
