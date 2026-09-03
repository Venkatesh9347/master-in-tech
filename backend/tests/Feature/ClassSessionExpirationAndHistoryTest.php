<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClassSessionExpirationAndHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $tutor;
    private User $student;
    private Course $course;
    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@rit.edu',
        ]);

        $this->tutor = User::factory()->create([
            'role' => 'tutor',
            'name' => 'Rakesh',
            'email' => 'rakesh@rit.edu',
        ]);

        $this->student = User::factory()->create([
            'role' => 'student',
            'name' => 'Aditya',
            'email' => 'aditya@rit.edu',
        ]);

        $this->course = Course::create([
            'title' => 'AI System Design',
            'slug' => 'ai-system-design',
            'description' => 'Comprehensive AI System Design Master Program',
            'instructor' => 'Rakesh',
            'category' => 'Artificial Intelligence',
            'duration' => '12 weeks',
            'difficulty' => 'Advanced',
            'code' => 'ASD',
            'status' => 'published',
            'price' => 2999,
            'is_published' => true,
        ]);

        CourseEnrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $this->batch = Batch::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'code' => 'RIT(ASD)BC230826',
            'name' => 'AI System Design Master Batch',
            'start_date' => '2026-08-23',
            'status' => 'active',
        ]);
    }

    public function test_automatic_expiration_transitions_past_sessions_to_expired(): void
    {
        $now = Carbon::now('Asia/Kolkata');
        $yesterday = $now->copy()->subDay()->toDateString();

        // 1. Session scheduled yesterday
        $pastSession = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Lecture 1: Introduction',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'meeting_id' => '123456789',
            'scheduled_date' => $yesterday,
            'start_time' => '10:00',
            'end_time' => '11:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        // 2. Session scheduled today in future
        $futureSession = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Lecture 2: Advanced Design',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/987654321',
            'meeting_id' => '987654321',
            'scheduled_date' => $now->copy()->addDays(2)->toDateString(),
            'start_time' => '15:00',
            'end_time' => '16:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $this->assertEquals('scheduled', $pastSession->fresh()->status);

        // Run expiration engine
        $expiredCount = ClassSession::expirePastSessions();

        $this->assertGreaterThanOrEqual(1, $expiredCount);
        $pastSession->refresh();
        $this->assertEquals('expired', $pastSession->status);
        $this->assertNotNull($pastSession->expired_at);
        $this->assertNotNull($pastSession->ended_at);

        // Future session remains scheduled
        $this->assertEquals('scheduled', $futureSession->fresh()->status);
    }

    public function test_admin_class_sessions_excludes_expired_and_history_includes_them(): void
    {
        Sanctum::actingAs($this->admin);

        $now = Carbon::now('Asia/Kolkata');
        $yesterday = $now->copy()->subDay()->toDateString();
        $tomorrow = $now->copy()->addDay()->toDateString();

        // Expired past session
        $pastSession = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Past AI System Design',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/111111111',
            'meeting_id' => '111111111',
            'scheduled_date' => $yesterday,
            'start_time' => '10:00',
            'end_time' => '11:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        // Active upcoming session
        $activeSession = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Upcoming AI System Design',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/222222222',
            'meeting_id' => '222222222',
            'scheduled_date' => $tomorrow,
            'start_time' => '15:00',
            'end_time' => '16:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        // 1. Current class sessions index
        $resCurrent = $this->getJson('/api/admin/class-sessions');
        $resCurrent->assertOk();
        $currentIds = collect($resCurrent->json('sessions'))->pluck('id');
        $this->assertContains($activeSession->id, $currentIds);
        $this->assertNotContains($pastSession->id, $currentIds);

        // 2. Class History endpoint
        $resHistory = $this->getJson('/api/admin/class-history');
        $resHistory->assertOk();
        $historyIds = collect($resHistory->json('history'))->pluck('id');
        $this->assertContains($pastSession->id, $historyIds);
        $this->assertNotContains($activeSession->id, $historyIds);

        // Check history record enrichment
        $historyRecord = collect($resHistory->json('history'))->firstWhere('id', $pastSession->id);
        $this->assertEquals('RIT(ASD)BC230826', $historyRecord['batch_code']);
        $this->assertEquals('ASD', $historyRecord['course_code']);
        $this->assertEquals('Rakesh', $historyRecord['tutor']['name']);
        $this->assertEquals('expired', $historyRecord['status']);
    }

    public function test_admin_class_history_filters_and_search(): void
    {
        Sanctum::actingAs($this->admin);

        $now = Carbon::now('Asia/Kolkata');
        $pastDate = $now->copy()->subDays(5)->toDateString();

        $session = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Neural Architecture Search',
            'platform' => 'teams',
            'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/12345',
            'meeting_id' => 'TEAMS-NAS-99',
            'meeting_password' => 'secret123',
            'scheduled_date' => $pastDate,
            'start_time' => '14:00',
            'end_time' => '15:30',
            'status' => 'completed',
            'created_by' => $this->admin->id,
        ]);

        // Filter by platform=teams
        $res = $this->getJson('/api/admin/class-history?platform=teams');
        $res->assertOk();
        $ids = collect($res->json('history'))->pluck('id');
        $this->assertContains($session->id, $ids);

        // Search by meeting ID
        $resSearch = $this->getJson('/api/admin/class-history?search=TEAMS-NAS-99');
        $resSearch->assertOk();
        $searchIds = collect($resSearch->json('history'))->pluck('id');
        $this->assertContains($session->id, $searchIds);

        // Search by batch code
        $resBatchSearch = $this->getJson('/api/admin/class-history?search=RIT(ASD)BC230826');
        $resBatchSearch->assertOk();
        $batchIds = collect($resBatchSearch->json('history'))->pluck('id');
        $this->assertContains($session->id, $batchIds);

        // Single History Show endpoint
        $resShow = $this->getJson("/api/admin/class-history/{$session->id}");
        $resShow->assertOk();
        $this->assertEquals('RIT(ASD)BC230826', $resShow->json('batch_code'));
        $this->assertEquals('ASD', $resShow->json('course_code'));
        $this->assertEquals('••••••••', $resShow->json('masked_password'));
    }

    public function test_student_and_tutor_dashboards_exclude_expired_sessions_from_active_list(): void
    {
        $now = Carbon::now('Asia/Kolkata');
        $yesterday = $now->copy()->subDay()->toDateString();
        $tomorrow = $now->copy()->addDay()->toDateString();

        // Expired session
        $expired = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Expired Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/333333333',
            'scheduled_date' => $yesterday,
            'start_time' => '10:00',
            'end_time' => '11:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        // Active upcoming session
        $active = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Active Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/444444444',
            'scheduled_date' => $tomorrow,
            'start_time' => '14:00',
            'end_time' => '15:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        // 1. Student upcoming endpoint
        Sanctum::actingAs($this->student);
        $resStudent = $this->getJson('/api/student/class-sessions/upcoming');
        $resStudent->assertOk();
        $studentUpcomingIds = collect($resStudent->json())->pluck('id');
        $this->assertContains($active->id, $studentUpcomingIds);
        $this->assertNotContains($expired->id, $studentUpcomingIds);

        // Student previous endpoint includes expired session
        $resStudentPrev = $this->getJson('/api/student/class-sessions/previous');
        $resStudentPrev->assertOk();
        $studentPrevIds = collect($resStudentPrev->json())->pluck('id');
        $this->assertContains($expired->id, $studentPrevIds);

        // 2. Tutor upcoming endpoint
        Sanctum::actingAs($this->tutor);
        $resTutor = $this->getJson('/api/tutor/class-sessions/upcoming');
        $resTutor->assertOk();
        $tutorUpcomingIds = collect($resTutor->json())->pluck('id');
        $this->assertContains($active->id, $tutorUpcomingIds);
        $this->assertNotContains($expired->id, $tutorUpcomingIds);

        // Tutor previous endpoint includes expired session
        $resTutorPrev = $this->getJson('/api/tutor/class-sessions/previous');
        $resTutorPrev->assertOk();
        $tutorPrevIds = collect($resTutorPrev->json())->pluck('id');
        $this->assertContains($expired->id, $tutorPrevIds);
    }
}
