<?php

namespace Tests\Feature;

use App\Models\ClassMaterial;
use App\Models\ClassSession;
use App\Models\ClassSessionAttendance;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Section;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardPerformanceBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;
    private User $student;
    private array $courses = [];
    private array $sessions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $sessionIdTutor = Str::random(40);
        $this->tutor = User::factory()->create([
            'role' => 'tutor',
            'current_session_id' => $sessionIdTutor,
        ]);

        $sessionIdStudent = Str::random(40);
        $this->student = User::factory()->create([
            'role' => 'student',
            'current_session_id' => $sessionIdStudent,
        ]);

        $today = Carbon::now('Asia/Kolkata')->toDateString();

        // Create 3 courses with sections, lessons, and quizzes
        for ($i = 1; $i <= 3; $i++) {
            $course = Course::create([
                'title' => "Advanced Fullstack Engineering Vol {$i}",
                'slug' => "adv-fullstack-{$i}-" . Str::random(4),
                'description' => "Comprehensive enterprise curriculum {$i}",
                'category' => 'Software Engineering',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'price' => 49999,
                'status' => 'published',
                'is_published' => true,
                'instructor_id' => $this->tutor->id,
                'instructor' => $this->tutor->name,
            ]);
            $this->courses[] = $course;

            // Enroll student
            CourseEnrollment::create([
                'user_id' => $this->student->id,
                'course_id' => $course->id,
                'status' => 'active',
                'progress_percentage' => 40.0,
                'enrolled_at' => now(),
            ]);

            // Create sections & lessons
            $section = Section::create([
                'course_id' => $course->id,
                'title' => "Section {$i}",
                'order' => 1,
            ]);

            for ($l = 1; $l <= 4; $l++) {
                $lesson = Lesson::create([
                    'course_id' => $course->id,
                    'section_id' => $section->id,
                    'title' => "Lesson {$l} of Course {$i}",
                    'slug' => "lesson-{$i}-{$l}-" . Str::random(4),
                    'duration' => 30,
                    'is_published' => true,
                    'order' => $l,
                ]);

                if ($l <= 2) {
                    LessonProgress::create([
                        'user_id' => $this->student->id,
                        'course_id' => $course->id,
                        'lesson_id' => $lesson->id,
                        'section_id' => $section->id,
                        'completed' => true,
                        'completed_at' => now(),
                        'last_accessed_at' => now(),
                    ]);
                }
            }

            // Create course quiz
            $quiz = Quiz::create([
                'lesson_id' => $lesson->id,
                'title' => "Module {$i} Mastery Check",
                'passing_score' => 70,
                'time_limit' => 20,
            ]);

            QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'question' => "What is primary advantage of indexed databases?",
                'type' => 'multiple_choice',
                'marks' => 5,
            ]);

            QuizAttempt::create([
                'user_id' => $this->student->id,
                'quiz_id' => $quiz->id,
                'lesson_id' => $lesson->id,
                'course_id' => $course->id,
                'score' => 85,
                'passed' => true,
                'completed_at' => now(),
            ]);

            // Create Today Session
            $sessionToday = ClassSession::create([
                'course_id' => $course->id,
                'tutor_id' => $this->tutor->id,
                'quiz_id' => $quiz->id,
                'title' => "Live Architecture Workshop Course {$i}",
                'description' => 'Interactive system design deep dive',
                'platform' => 'zoom',
                'meeting_url' => 'https://zoom.us/j/999888777',
                'scheduled_date' => $today,
                'start_time' => '10:00',
                'end_time' => '11:30',
                'status' => 'scheduled',
                'created_by' => $this->tutor->id,
            ]);

            // Create Upcoming Session
            $sessionUpcoming = ClassSession::create([
                'course_id' => $course->id,
                'tutor_id' => $this->tutor->id,
                'quiz_id' => $quiz->id,
                'title' => "Upcoming Microservices Workshop Course {$i}",
                'description' => 'Docker & Kubernetes cluster orchestration',
                'platform' => 'zoom',
                'meeting_url' => 'https://zoom.us/j/999888666',
                'scheduled_date' => Carbon::now('Asia/Kolkata')->addDays(3)->toDateString(),
                'start_time' => '14:00',
                'end_time' => '15:30',
                'status' => 'scheduled',
                'created_by' => $this->tutor->id,
            ]);

            // Create Previous Session
            $sessionPrevious = ClassSession::create([
                'course_id' => $course->id,
                'tutor_id' => $this->tutor->id,
                'quiz_id' => $quiz->id,
                'title' => "Completed Fundamentals Lecture Course {$i}",
                'description' => 'Database indexing and concurrency models',
                'platform' => 'teams',
                'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/12345',
                'scheduled_date' => Carbon::now('Asia/Kolkata')->subDays(2)->toDateString(),
                'start_time' => '11:00',
                'end_time' => '12:30',
                'status' => 'completed',
                'created_by' => $this->tutor->id,
            ]);

            // Add Materials & Attendance
            ClassMaterial::create([
                'class_session_id' => $sessionPrevious->id,
                'course_id' => $course->id,
                'uploaded_by' => $this->tutor->id,
                'title' => "Slide Deck Vol {$i}.pdf",
                'file_path' => "materials/test-{$i}.pdf",
                'file_name' => "test-{$i}.pdf",
                'file_type' => 'application/pdf',
                'file_size' => 102400,
            ]);

            ClassSessionAttendance::create([
                'class_session_id' => $sessionPrevious->id,
                'user_id' => $this->student->id,
                'status' => 'present',
                'joined_at' => now(),
            ]);

            $this->sessions[] = $sessionToday;
            $this->sessions[] = $sessionUpcoming;
            $this->sessions[] = $sessionPrevious;
        }
    }

    public function test_student_dashboard_endpoints_are_optimized_without_n_plus_one_queries()
    {
        $token = $this->student->createToken('test', ['session:' . $this->student->current_session_id])->plainTextToken;

        $endpoints = [
            'my_courses' => '/api/my-courses',
            'today_sessions' => '/api/student/class-sessions/today',
            'upcoming_sessions' => '/api/student/class-sessions/upcoming',
            'previous_sessions' => '/api/student/class-sessions/previous',
            'all_sessions' => '/api/student/class-sessions',
        ];

        foreach ($endpoints as $label => $uri) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $startTime = microtime(true);
            $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson($uri);
            $durationMs = (microtime(true) - $startTime) * 1000;

            $queryCount = count(DB::getQueryLog());
            DB::disableQueryLog();

            $response->assertStatus(200);

            // Assert that query count is small and bounded (strictly no N+1 behavior)
            $this->assertLessThanOrEqual(14, $queryCount, "Endpoint {$label} executed {$queryCount} queries, possible N+1 regression.");
        }
    }

    public function test_tutor_dashboard_endpoints_are_optimized_without_n_plus_one_queries()
    {
        $token = $this->tutor->createToken('test', ['session:' . $this->tutor->current_session_id])->plainTextToken;

        $endpoints = [
            'stats' => '/api/tutor/stats',
            'courses' => '/api/tutor/courses',
            'today_sessions' => '/api/tutor/class-sessions/today',
            'upcoming_sessions' => '/api/tutor/class-sessions/upcoming',
            'previous_sessions' => '/api/tutor/class-sessions/previous',
            'students' => '/api/tutor/students',
            'materials' => '/api/tutor/materials',
            'quizzes' => '/api/tutor/quizzes',
        ];

        foreach ($endpoints as $label => $uri) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $startTime = microtime(true);
            $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson($uri);
            $durationMs = (microtime(true) - $startTime) * 1000;

            $queryCount = count(DB::getQueryLog());
            DB::disableQueryLog();

            $response->assertStatus(200);

            $this->assertLessThanOrEqual(12, $queryCount, "Tutor endpoint {$label} executed {$queryCount} queries, possible N+1 regression.");
        }
    }
}
