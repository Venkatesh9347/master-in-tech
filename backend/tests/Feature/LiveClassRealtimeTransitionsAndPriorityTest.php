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

class LiveClassRealtimeTransitionsAndPriorityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $tutor;
    private User $student;
    private Course $course1;
    private Course $course2;
    private Batch $batch1;
    private Batch $batch2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 14, 0, 0, 'Asia/Kolkata'));

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@rit.edu',
        ]);

        $this->tutor = User::factory()->create([
            'role' => 'tutor',
            'name' => 'Prof. Rakesh',
            'email' => 'rakesh@rit.edu',
        ]);

        $this->student = User::factory()->create([
            'role' => 'student',
            'name' => 'Aditya Sharma',
            'email' => 'aditya@rit.edu',
        ]);

        $this->course1 = Course::create([
            'title' => 'Advanced AI & Algorithms',
            'slug' => 'advanced-ai-algorithms',
            'description' => 'Advanced Algorithms',
            'instructor' => 'Prof. Rakesh',
            'category' => 'AI',
            'duration' => '10 weeks',
            'difficulty' => 'Advanced',
            'code' => 'AAA',
            'status' => 'published',
            'price' => 1999,
            'is_published' => true,
        ]);

        $this->course2 = Course::create([
            'title' => 'AI System Design',
            'slug' => 'ai-system-design',
            'description' => 'System Design Course',
            'instructor' => 'Prof. Rakesh',
            'category' => 'AI',
            'duration' => '12 weeks',
            'difficulty' => 'Advanced',
            'code' => 'ASD',
            'status' => 'published',
            'price' => 2999,
            'is_published' => true,
        ]);

        CourseEnrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course1->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        CourseEnrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course2->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $this->batch1 = Batch::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'code' => 'RIT(AAA)BC230826',
            'name' => 'AAA Batch 1',
            'start_date' => '2026-08-23',
            'status' => 'active',
        ]);

        $this->batch2 = Batch::create([
            'course_id' => $this->course2->id,
            'tutor_id' => $this->tutor->id,
            'code' => 'RIT(ASD)BC230826',
            'name' => 'ASD Batch 1',
            'start_date' => '2026-08-23',
            'status' => 'active',
        ]);
    }

    /**
     * Test 1: Before start_time -> Status is SCHEDULED (Normal dashboard mode)
     */
    public function test_lifecycle_before_start(): void
    {
        $session = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Class Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'scheduled_date' => '2026-08-23',
            'start_time' => '15:00',
            'end_time' => '16:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $beforeStart = Carbon::parse('2026-08-23 14:59:59', 'Asia/Kolkata');
        $this->assertEquals('scheduled', $session->calculateStatus($beforeStart));
    }

    /**
     * Test 2: Exactly at start_time -> Status is LIVE (Switches into Live Class Mode)
     */
    public function test_lifecycle_exactly_at_start(): void
    {
        $session = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Class Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'scheduled_date' => '2026-08-23',
            'start_time' => '15:00',
            'end_time' => '16:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $atStart = Carbon::parse('2026-08-23 15:00:00', 'Asia/Kolkata');
        $this->assertEquals('live', $session->calculateStatus($atStart));
    }

    /**
     * Test 3: During class (start_time < current_time < end_time) -> Status is LIVE
     */
    public function test_lifecycle_during_class(): void
    {
        $session = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Class Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'scheduled_date' => '2026-08-23',
            'start_time' => '15:00',
            'end_time' => '16:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $duringClass = Carbon::parse('2026-08-23 15:45:00', 'Asia/Kolkata');
        $this->assertEquals('live', $session->calculateStatus($duringClass));
    }

    /**
     * Test 4: Exactly at end_time -> Status is EXPIRED (Switches back to Normal dashboard mode)
     */
    public function test_lifecycle_exactly_at_end(): void
    {
        $session = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Class Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'scheduled_date' => '2026-08-23',
            'start_time' => '15:00',
            'end_time' => '16:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $atEnd = Carbon::parse('2026-08-23 16:30:00', 'Asia/Kolkata');
        $this->assertEquals('expired', $session->calculateStatus($atEnd));
    }

    /**
     * Test 5: After end_time -> Status is EXPIRED (Normal dashboard mode restored, session in History)
     */
    public function test_lifecycle_after_end(): void
    {
        $session = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Class Session',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'scheduled_date' => '2026-08-23',
            'start_time' => '15:00',
            'end_time' => '16:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $afterEnd = Carbon::parse('2026-08-23 16:31:00', 'Asia/Kolkata');
        $this->assertEquals('expired', $session->calculateStatus($afterEnd));
    }

    /**
     * Test 6: Consecutive live classes transition automatically:
     * Class 1: 15:00–16:30 IST
     * Class 2: 17:00–18:30 IST
     */
    public function test_consecutive_live_classes_transition(): void
    {
        $today = '2026-08-23';

        $class1 = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Lecture 1: Algorithms',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/111111',
            'scheduled_date' => $today,
            'start_time' => '15:00',
            'end_time' => '16:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        $class2 = ClassSession::create([
            'course_id' => $this->course2->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Lecture 2: System Design',
            'platform' => 'teams',
            'meeting_url' => 'https://teams.microsoft.com/222222',
            'scheduled_date' => $today,
            'start_time' => '17:00',
            'end_time' => '18:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        // At 15:30 IST -> Class 1 is LIVE, Class 2 is SCHEDULED
        $t1530 = Carbon::parse('2026-08-23 15:30:00', 'Asia/Kolkata');
        $this->assertEquals('live', $class1->calculateStatus($t1530));
        $this->assertEquals('scheduled', $class2->calculateStatus($t1530));

        // At 16:45 IST (between classes) -> Class 1 is EXPIRED, Class 2 is SCHEDULED (Normal Mode)
        $t1645 = Carbon::parse('2026-08-23 16:45:00', 'Asia/Kolkata');
        $this->assertEquals('expired', $class1->calculateStatus($t1645));
        $this->assertEquals('scheduled', $class2->calculateStatus($t1645));

        // At 17:00 IST -> Class 1 is EXPIRED, Class 2 automatically becomes LIVE NOW
        $t1700 = Carbon::parse('2026-08-23 17:00:00', 'Asia/Kolkata');
        $this->assertEquals('expired', $class1->calculateStatus($t1700));
        $this->assertEquals('live', $class2->calculateStatus($t1700));
    }

    /**
     * Test 7: Student Live Mode & Automatic return to normal dashboard
     */
    public function test_student_live_mode_and_return_to_normal_dashboard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 14:00:00', 'Asia/Kolkata'));
        $nowIst = Carbon::now('Asia/Kolkata');
        $today = $nowIst->toDateString();

        // 1. Session is currently live
        $liveSession = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Student Active Live Lecture',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/live-now',
            'scheduled_date' => $today,
            'start_time' => $nowIst->copy()->subMinutes(15)->format('H:i'),
            'end_time' => $nowIst->copy()->addMinutes(45)->format('H:i'),
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->student);

        // Student fetches today's sessions -> receives LIVE class with authoritative batch code
        $resLive = $this->getJson('/api/student/class-sessions/today');
        $resLive->assertOk();
        $liveData = $resLive->json();
        $this->assertNotEmpty($liveData);
        $this->assertEquals($liveSession->id, $liveData[0]['id']);
        $this->assertEquals('live', $liveData[0]['status']);
        $this->assertTrue($liveData[0]['is_live']);
        $this->assertEquals('RIT(AAA)BC230826', $liveData[0]['batch_code']);
        $this->assertEquals('https://zoom.us/live-now', $liveData[0]['meeting_url']);

        // 2. Class reaches end_time and expires
        $liveSession->update([
            'start_time' => $nowIst->copy()->subHours(2)->format('H:i'),
            'end_time' => $nowIst->copy()->subMinutes(5)->format('H:i'),
            'status' => 'scheduled',
        ]);

        // Student fetches today's sessions -> active live class is gone (returns to Normal Mode)
        $resExpired = $this->getJson('/api/student/class-sessions/today');
        $resExpired->assertOk();
        $expiredData = $resExpired->json();
        $this->assertEmpty($expiredData);

        // Previous classes now includes the expired session
        $resPrev = $this->getJson('/api/student/class-sessions/previous');
        $resPrev->assertOk();
        $prevData = $resPrev->json();
        $this->assertNotEmpty($prevData);
        $this->assertEquals($liveSession->id, $prevData[0]['id']);
        $this->assertEquals('RIT(AAA)BC230826', $prevData[0]['batch_code']);

        Carbon::setTestNow(null);
    }

    /**
     * Test 8: Faculty Live Mode & Automatic return to normal dashboard
     */
    public function test_faculty_live_mode_and_return_to_normal_dashboard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 14:00:00', 'Asia/Kolkata'));
        $nowIst = Carbon::now('Asia/Kolkata');
        $today = $nowIst->toDateString();

        // 1. Session currently live for Faculty
        $liveSession = ClassSession::create([
            'course_id' => $this->course2->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Faculty Active Live Lecture',
            'platform' => 'teams',
            'meeting_url' => 'https://teams.microsoft.com/faculty-live',
            'scheduled_date' => $today,
            'start_time' => $nowIst->copy()->subMinutes(10)->format('H:i'),
            'end_time' => $nowIst->copy()->addMinutes(50)->format('H:i'),
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->tutor);

        // Faculty fetches today's sessions -> receives LIVE class
        $resLive = $this->getJson('/api/tutor/class-sessions/today');
        $resLive->assertOk();
        $tutorLiveData = $resLive->json();
        $this->assertNotEmpty($tutorLiveData);

        Carbon::setTestNow(null);
        $this->assertEquals($liveSession->id, $tutorLiveData[0]['id']);
        $this->assertEquals('live', $tutorLiveData[0]['status']);
        $this->assertTrue($tutorLiveData[0]['is_live']);
        $this->assertEquals('RIT(ASD)BC230826', $tutorLiveData[0]['batch_code']);

        // 2. Class reaches end_time and expires
        $liveSession->update([
            'start_time' => $nowIst->copy()->subHours(2)->format('H:i'),
            'end_time' => $nowIst->copy()->subMinutes(1)->format('H:i'),
            'status' => 'scheduled',
        ]);

        // Faculty fetches today's sessions -> active live class is removed
        $resExpired = $this->getJson('/api/tutor/class-sessions/today');
        $resExpired->assertOk();
        $this->assertEmpty($resExpired->json());

        // Previous classes includes expired class
        $resPrev = $this->getJson('/api/tutor/class-sessions/previous');
        $resPrev->assertOk();
        $this->assertNotEmpty($resPrev->json());
        $this->assertEquals($liveSession->id, $resPrev->json()[0]['id']);
    }

    /**
     * Test 9: Admin Class History retains all preserved details after expiration
     */
    public function test_class_history_retains_all_preserved_details_after_expiration(): void
    {
        Sanctum::actingAs($this->admin);

        $nowIst = Carbon::now('Asia/Kolkata');
        $today = $nowIst->toDateString();

        $session = ClassSession::create([
            'course_id' => $this->course1->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Advanced Algorithms Lab',
            'description' => 'Graph algorithms deep dive',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/999888777',
            'meeting_id' => '999 888 777',
            'meeting_password' => 'supersecretpass',
            'scheduled_date' => $today,
            'start_time' => $nowIst->copy()->subHours(3)->format('H:i'),
            'end_time' => $nowIst->copy()->subHours(1)->format('H:i'),
            'status' => 'scheduled',
            'admin_notes' => 'Internal faculty debrief note',
            'created_by' => $this->admin->id,
        ]);

        // Class History index
        $res = $this->getJson('/api/admin/class-history');
        $res->assertOk();
        $historyList = $res->json('history');

        $record = collect($historyList)->firstWhere('id', $session->id);
        $this->assertNotNull($record);
        $this->assertEquals($session->id, $record['id']);
        $this->assertEquals('RIT(AAA)BC230826', $record['batch_code']);
        $this->assertEquals('Advanced AI & Algorithms', $record['course']['title']);
        $this->assertEquals('AAA', $record['course_code']);
        $this->assertEquals('Prof. Rakesh', $record['tutor']['name']);
        $this->assertEquals($this->tutor->id, $record['tutor_id']);
        $this->assertEquals('zoom', $record['platform']);
        $this->assertEquals('999 888 777', $record['meeting_id']);
        $this->assertEquals('https://zoom.us/j/999888777', $record['meeting_url']);
        $this->assertEquals('expired', $record['status']);
        $this->assertNotNull($record['created_at']);

        // Class History detail show endpoint
        $resShow = $this->getJson("/api/admin/class-history/{$session->id}");
        $resShow->assertOk();
        $this->assertEquals('RIT(AAA)BC230826', $resShow->json('batch_code'));
        $this->assertEquals('••••••••', $resShow->json('masked_password'));
        $this->assertEquals('Internal faculty debrief note', $resShow->json('admin_notes'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
