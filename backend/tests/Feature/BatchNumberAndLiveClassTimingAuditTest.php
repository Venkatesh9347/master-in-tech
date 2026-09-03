<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BatchNumberAndLiveClassTimingAuditTest extends TestCase
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
            'email' => 'admin_audit@example.com',
        ]);

        $this->tutor = User::factory()->create([
            'role' => 'tutor',
            'name' => 'Dr. Jane Tutor',
            'email' => 'tutor_audit@example.com',
        ]);

        $this->student = User::factory()->create([
            'role' => 'student',
            'name' => 'Alice Student',
            'email' => 'student_audit@example.com',
        ]);

        $this->course = Course::create([
            'title' => 'Artificial Intelligence Masterclass',
            'slug' => 'artificial-intelligence-masterclass',
            'description' => 'Comprehensive AI Course',
            'instructor' => 'Dr. Jane Tutor',
            'category' => 'Artificial Intelligence',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'code' => 'AI',
            'status' => 'published',
            'is_published' => true,
            'price' => 1000,
        ]);

        $this->batch = Batch::create([
            'name' => 'AI Alpha Batch',
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'start_date' => '2026-08-23',
            'code' => 'RIT(AI)BC230826',
            'status' => 'ongoing',
        ]);

        BatchStudent::create([
            'batch_id' => $this->batch->id,
            'user_id' => $this->student->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        CourseEnrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);
    }

    public function test_batch_code_generator_derives_correct_format(): void
    {
        $generatedFresh = Batch::generateBatchCode($this->course, '2026-08-24');
        $this->assertEquals('RIT(AI)BC240826', $generatedFresh);

        $generatedFuture = Batch::generateBatchCode($this->course, '2026-09-01');
        $this->assertEquals('RIT(AI)BC010926', $generatedFuture);

        // Existing code on 2026-08-23 creates suffix -02
        $generatedCollision = Batch::generateBatchCode($this->course, '2026-08-23');
        $this->assertEquals('RIT(AI)BC230826-02', $generatedCollision);
    }

    public function test_student_class_sessions_include_authoritative_batch_code(): void
    {
        $nowIst = Carbon::now('Asia/Kolkata');
        $session = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Introduction to Neural Networks',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'scheduled_date' => $nowIst->toDateString(),
            'start_time' => '00:00',
            'end_time' => '23:59',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/student/class-sessions/today');
        $response->assertOk();
        $data = $response->json();

        $this->assertCount(1, $data);
        $this->assertEquals('RIT(AI)BC230826', $data[0]['batch_code']);
        $this->assertEquals('RIT(AI)BC230826', $data[0]['batch_number']);
        $this->assertNotNull($data[0]['batch']);
        $this->assertEquals($this->batch->id, $data[0]['batch']['id']);
    }

    public function test_tutor_class_sessions_include_authoritative_batch_code(): void
    {
        $nowIst = Carbon::now('Asia/Kolkata');
        $session = ClassSession::create([
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Introduction to Neural Networks',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/123456789',
            'scheduled_date' => $nowIst->toDateString(),
            'start_time' => '00:00',
            'end_time' => '23:59',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->tutor);

        $response = $this->getJson('/api/tutor/class-sessions/today');
        $response->assertOk();
        $data = $response->json();

        $this->assertCount(1, $data);
        $this->assertEquals('RIT(AI)BC230826', $data[0]['batch_code']);
        $this->assertEquals('RIT(AI)BC230826', $data[0]['batch_number']);
        $this->assertNotNull($data[0]['batch']);
        $this->assertEquals($this->batch->id, $data[0]['batch']['id']);
    }

    public function test_admin_creating_and_updating_batch_records_audit_logs(): void
    {
        Sanctum::actingAs($this->admin);

        // 1. Create Batch
        $createRes = $this->postJson('/api/admin/batches', [
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'start_date' => '2026-09-01',
            'status' => 'upcoming',
        ]);
        $createRes->assertCreated();
        $batchId = $createRes->json('batch.id');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created_batch',
            'auditable_type' => Batch::class,
            'auditable_id' => $batchId,
        ]);

        // 2. Update Batch
        $updateRes = $this->putJson("/api/admin/batches/{$batchId}", [
            'start_date' => '2026-09-15',
            'code' => 'RIT(AI)BC150926',
            'status' => 'upcoming',
        ]);
        $updateRes->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'updated_batch',
            'auditable_type' => Batch::class,
            'auditable_id' => $batchId,
        ]);

        // 3. Delete Batch
        $deleteRes = $this->deleteJson("/api/admin/batches/{$batchId}");
        $deleteRes->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'deleted_batch',
        ]);
    }

    public function test_admin_class_session_crud_records_audit_logs(): void
    {
        Sanctum::actingAs($this->admin);

        // 1. Create Class Session
        $createRes = $this->postJson('/api/admin/class-sessions', [
            'course_id' => $this->course->id,
            'tutor_id' => $this->tutor->id,
            'title' => 'Deep Learning Fundamentals',
            'platform' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/987654321',
            'scheduled_date' => '2026-08-25',
            'start_time' => '14:00',
            'end_time' => '15:30',
            'status' => 'scheduled',
        ]);
        $createRes->assertCreated();
        $sessionId = $createRes->json('session.id');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created_class_session',
            'auditable_type' => ClassSession::class,
            'auditable_id' => $sessionId,
        ]);

        // 2. Cancel Class Session
        $cancelRes = $this->postJson("/api/admin/class-sessions/{$sessionId}/cancel");
        $cancelRes->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cancelled_class_session',
            'auditable_type' => ClassSession::class,
            'auditable_id' => $sessionId,
        ]);
    }

    public function test_student_and_tutor_cannot_access_unassigned_classes(): void
    {
        $otherCourse = Course::create([
            'title' => 'Cybersecurity Operations',
            'slug' => 'cybersecurity-operations',
            'description' => 'Cybersecurity Operations Course',
            'instructor' => 'Security Expert',
            'category' => 'Cybersecurity',
            'duration' => '6 weeks',
            'difficulty' => 'Beginner',
            'code' => 'CS',
            'status' => 'published',
            'is_published' => true,
            'price' => 1000,
        ]);
        $otherTutor = User::factory()->create(['role' => 'tutor']);
        $otherSession = ClassSession::create([
            'course_id' => $otherCourse->id,
            'tutor_id' => $otherTutor->id,
            'title' => 'Threat Hunting 101',
            'platform' => 'teams',
            'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/123',
            'scheduled_date' => '2026-08-24',
            'start_time' => '10:00',
            'end_time' => '11:30',
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
        ]);

        // Student tries to view other course session
        Sanctum::actingAs($this->student);
        $resStudent = $this->getJson("/api/student/class-sessions/{$otherSession->id}");
        $resStudent->assertForbidden();

        // Tutor tries to view other tutor session
        Sanctum::actingAs($this->tutor);
        $resTutor = $this->getJson("/api/tutor/class-sessions/{$otherSession->id}");
        $resTutor->assertForbidden();
    }
}
