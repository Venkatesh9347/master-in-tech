<?php

namespace Tests\Feature;

use App\Models\ClassMaterial;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\Section;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class Goal7StudentDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $studentAlice;
    private User $studentBob;
    private User $tutor;
    private Course $course1;
    private Course $course2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studentAlice = User::factory()->create([
            'name' => 'Alice Learner',
            'email' => 'alice@masterintech.test',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->studentBob = User::factory()->create([
            'name' => 'Bob Learner',
            'email' => 'bob@masterintech.test',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->tutor = User::factory()->create([
            'name' => 'Rakesh Faculty',
            'email' => 'rakesh@masterintech.test',
            'password' => Hash::make('password123'),
            'role' => 'tutor',
            'status' => 'active',
        ]);

        $this->course1 = Course::create([
            'title' => 'Full Stack Development',
            'slug' => 'full-stack-dev',
            'description' => 'MERN Stack & Next.js training program',
            'instructor' => 'Rakesh Faculty',
            'instructor_id' => $this->tutor->id,
            'duration' => '12 Weeks',
            'difficulty' => 'Intermediate',
            'price' => 199.00,
            'is_published' => true,
        ]);

        $this->course2 = Course::create([
            'title' => 'Cloud & DevOps Engineering',
            'slug' => 'cloud-devops',
            'description' => 'AWS, Docker & Kubernetes program',
            'instructor' => 'Rakesh Faculty',
            'instructor_id' => $this->tutor->id,
            'duration' => '8 Weeks',
            'difficulty' => 'Advanced',
            'price' => 249.00,
            'is_published' => true,
        ]);

        // Alice is enrolled in Course 1 only
        CourseEnrollment::create([
            'user_id' => $this->studentAlice->id,
            'course_id' => $this->course1->id,
            'enrolled_at' => now(),
            'status' => 'active',
            'progress_percentage' => 40,
        ]);

        // Bob is enrolled in Course 2 only
        CourseEnrollment::create([
            'user_id' => $this->studentBob->id,
            'course_id' => $this->course2->id,
            'enrolled_at' => now(),
            'status' => 'active',
            'progress_percentage' => 10,
        ]);
    }

    public function test_student_can_see_todays_enrolled_class(): void
    {
        $nowIst = Carbon::now('Asia/Kolkata');
        $today = $nowIst->toDateString();

        $sessionToday = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'HTML & CSS Fundamentals Live',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/111222333',
            'meeting_id' => '111 222 333',
            'scheduled_date' => $today,
            'start_time' => '00:00',
            'end_time' => '23:59',
            'status' => 'scheduled',
            'created_by' => $this->tutor->id,
        ]);

        $res = $this->actingAs($this->studentAlice, 'sanctum')->getJson('/api/student/class-sessions/today');

        $res->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $sessionToday->id)
            ->assertJsonPath('0.title', 'HTML & CSS Fundamentals Live')
            ->assertJsonPath('0.tutor_name', 'Rakesh Faculty')
            ->assertJsonPath('0.meeting_url', 'https://zoom.us/j/111222333');
    }

    public function test_student_can_see_upcoming_classes(): void
    {
        $tomorrow = Carbon::now('Asia/Kolkata')->addDays(1)->toDateString();

        $upcomingSession = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'React Hooks & State Management',
            'platform' => 'teams',
            'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/444',
            'scheduled_date' => $tomorrow,
            'start_time' => '17:00',
            'end_time' => '18:30',
            'status' => 'scheduled',
            'created_by' => $this->tutor->id,
        ]);

        $res = $this->actingAs($this->studentAlice, 'sanctum')->getJson('/api/student/class-sessions/upcoming');

        $res->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $upcomingSession->id)
            ->assertJsonPath('0.title', 'React Hooks & State Management');
    }

    public function test_student_can_see_previous_classes_with_material_and_quiz_indicators(): void
    {
        $yesterday = Carbon::now('Asia/Kolkata')->subDays(1)->toDateString();

        $prevSession = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'JavaScript ES6 Deep Dive',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/555666777',
            'scheduled_date' => $yesterday,
            'start_time' => '10:00',
            'end_time' => '11:30',
            'status' => 'completed',
            'recording_url' => 'https://recordings.masterintech.test/js-es6.mp4',
            'created_by' => $this->tutor->id,
        ]);

        // Attach 2 materials
        ClassMaterial::create([
            'course_id' => $this->course1->id,
            'class_session_id' => $prevSession->id,
            'uploaded_by' => $this->tutor->id,
            'title' => 'ES6 Notes PDF',
            'file_path' => '/storage/materials/es6_notes.pdf',
            'file_name' => 'es6_notes.pdf',
            'file_size' => 102400,
        ]);

        ClassMaterial::create([
            'course_id' => $this->course1->id,
            'class_session_id' => $prevSession->id,
            'uploaded_by' => $this->tutor->id,
            'title' => 'Code Exercises Zip',
            'file_path' => '/storage/materials/exercises.zip',
            'file_name' => 'exercises.zip',
            'file_size' => 204800,
        ]);

        $res = $this->actingAs($this->studentAlice, 'sanctum')->getJson('/api/student/class-sessions/previous');

        $res->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $prevSession->id)
            ->assertJsonPath('0.materials_count', 2)
            ->assertJsonPath('0.materials_text', '2 shared')
            ->assertJsonPath('0.recording_url', 'https://recordings.masterintech.test/js-es6.mp4');
    }

    public function test_student_can_view_completed_class_details(): void
    {
        $session = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'CSS Grid & Flexbox Lab',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/777888999',
            'scheduled_date' => Carbon::now('Asia/Kolkata')->subDays(2)->toDateString(),
            'start_time' => '14:00',
            'end_time' => '15:30',
            'status' => 'completed',
            'recording_url' => 'https://recordings.masterintech.test/css-grid.mp4',
            'created_by' => $this->tutor->id,
        ]);

        $res = $this->actingAs($this->studentAlice, 'sanctum')->getJson("/api/student/class-sessions/{$session->id}");

        $res->assertStatus(200)
            ->assertJsonPath('id', $session->id)
            ->assertJsonPath('title', 'CSS Grid & Flexbox Lab')
            ->assertJsonPath('recording_url', 'https://recordings.masterintech.test/css-grid.mp4');
    }

    public function test_student_can_see_materials(): void
    {
        $session = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'HTML5 Canvas Masterclass',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/12345',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '11:00',
            'end_time' => '12:30',
            'status' => 'scheduled',
            'created_by' => $this->tutor->id,
        ]);

        ClassMaterial::create([
            'course_id' => $this->course1->id,
            'class_session_id' => $session->id,
            'uploaded_by' => $this->tutor->id,
            'title' => 'Canvas Guide PDF',
            'description' => 'Comprehensive 2D context slides',
            'file_path' => '/storage/materials/canvas_guide.pdf',
            'file_name' => 'canvas_guide.pdf',
            'file_type' => 'pdf',
            'file_size' => 524288,
        ]);

        $res = $this->actingAs($this->studentAlice, 'sanctum')->getJson("/api/student/class-sessions/{$session->id}");

        $res->assertStatus(200)
            ->assertJsonCount(1, 'materials')
            ->assertJsonPath('materials.0.title', 'Canvas Guide PDF')
            ->assertJsonPath('materials.0.file_name', 'canvas_guide.pdf')
            ->assertJsonPath('materials.0.file_type', 'pdf');
    }

    public function test_student_cannot_see_another_student_course_sessions(): void
    {
        // Session in Course 1 (Alice enrolled)
        $session1 = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Alice Course Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/111',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'status' => 'scheduled',
            'created_by' => $this->tutor->id,
        ]);

        // Session in Course 2 (Bob enrolled, Alice NOT enrolled)
        $session2 = ClassSession::create([
            'course_id' => $this->course2->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Bob DevOps Session',
            'platform' => 'teams',
            'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/222',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '12:00',
            'end_time' => '13:00',
            'status' => 'scheduled',
            'created_by' => $this->tutor->id,
        ]);

        $res = $this->actingAs($this->studentAlice, 'sanctum')->getJson('/api/student/class-sessions');

        $res->assertStatus(200);
        $ids = collect($res->json())->pluck('id')->toArray();
        $this->assertContains($session1->id, $ids);
        $this->assertNotContains($session2->id, $ids);
    }

    public function test_student_cannot_access_another_course_session_returns_403(): void
    {
        $session2 = ClassSession::create([
            'course_id' => $this->course2->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Unauthorized DevOps Session',
            'platform' => 'teams',
            'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/999',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '12:00',
            'end_time' => '13:00',
            'status' => 'scheduled',
            'created_by' => $this->tutor->id,
        ]);

        // Alice tries to directly show Bob's session details
        $res = $this->actingAs($this->studentAlice, 'sanctum')->getJson("/api/student/class-sessions/{$session2->id}");

        $res->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized. You are not enrolled in the course for this session.');
    }

    public function test_student_cannot_join_cancelled_class(): void
    {
        $cancelledSession = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Cancelled Workshop',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/999',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '15:00',
            'end_time' => '16:00',
            'status' => 'cancelled',
            'created_by' => $this->tutor->id,
        ]);

        $res = $this->actingAs($this->studentAlice, 'sanctum')->postJson("/api/student/class-sessions/{$cancelledSession->id}/join");

        $res->assertStatus(400)
            ->assertJsonPath('message', 'This class has been cancelled and cannot be joined.');
    }

    public function test_student_can_access_valid_meeting_url_and_logs_attendance(): void
    {
        $session = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Live Node.js API Workshop',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/888777666',
            'meeting_id' => '888 777 666',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '18:00',
            'end_time' => '19:30',
            'status' => 'scheduled',
            'created_by' => $this->tutor->id,
        ]);

        $res = $this->actingAs($this->studentAlice, 'sanctum')->postJson("/api/student/class-sessions/{$session->id}/join");

        $res->assertStatus(200)
            ->assertJsonPath('meeting_url', 'https://zoom.us/j/888777666')
            ->assertJsonPath('platform', 'zoom');

        $this->assertDatabaseHas('class_session_attendances', [
            'class_session_id' => $session->id,
            'user_id' => $this->studentAlice->id,
            'status' => 'present',
        ]);
    }

    public function test_quiz_is_shown_only_when_assigned_with_question_count_and_status(): void
    {
        // 1. Course with Quiz
        $section = Section::create([
            'course_id' => $this->course1->id,
            'title' => 'Module 1: React Basics',
            'sort_order' => 1,
        ]);

        $lesson = Lesson::create([
            'course_id' => $this->course1->id,
            'section_id' => $section->id,
            'title' => 'Components & Props',
            'slug' => 'components-and-props',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $quiz = Quiz::create([
            'lesson_id' => $lesson->id,
            'title' => 'React Fundamentals Quiz',
            'description' => 'Test your understanding of React basics',
            'time_limit' => 15,
            'passing_score' => 70,
            'is_published' => true,
        ]);

        QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'What is JSX?',
            'type' => 'multiple_choice',
            'marks' => 1,
            'sort_order' => 1,
        ]);

        QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'Which hook is used for side effects?',
            'type' => 'multiple_choice',
            'marks' => 1,
            'sort_order' => 2,
        ]);

        $sessionWithQuiz = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'quiz_id' => $quiz->id,
            'title' => 'React Session with Quiz',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/12345',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'status' => 'completed',
            'created_by' => $this->tutor->id,
        ]);

        $resWithQuiz = $this->actingAs($this->studentAlice, 'sanctum')->getJson("/api/student/class-sessions/{$sessionWithQuiz->id}");

        $resWithQuiz->assertStatus(200)
            ->assertJsonPath('quiz_status', 'Available')
            ->assertJsonPath('quiz.title', 'React Fundamentals Quiz')
            ->assertJsonPath('quiz.questions_count', 2);

        // 2. Course without Quiz
        $sessionWithoutQuiz = ClassSession::create([
            'course_id' => $this->course2->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'DevOps Session Without Quiz',
            'platform' => 'teams',
            'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/999',
            'scheduled_date' => now()->toDateString(),
            'start_time' => '14:00',
            'end_time' => '15:00',
            'status' => 'completed',
            'created_by' => $this->tutor->id,
        ]);

        $resWithoutQuiz = $this->actingAs($this->studentBob, 'sanctum')->getJson("/api/student/class-sessions/{$sessionWithoutQuiz->id}");

        $resWithoutQuiz->assertStatus(200)
            ->assertJsonPath('quiz_status', 'Not assigned')
            ->assertJsonPath('quiz', null);
    }
}
