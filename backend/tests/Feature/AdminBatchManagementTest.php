<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\BatchTransfer;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminBatchManagementTest extends TestCase
{
    use RefreshDatabase;

    private function createCourse(array $attributes = []): Course
    {
        $title = $attributes['title'] ?? 'Course ' . Str::random(5);
        return Course::create(array_merge([
            'title' => $title,
            'slug' => Str::slug($title),
            'code' => $attributes['code'] ?? 'FSD',
            'description' => 'Comprehensive technical training course description.',
            'category' => 'Engineering',
            'instructor' => 'Senior Specialist',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ], $attributes));
    }

    public function test_guest_and_unauthorized_users_cannot_access_batch_management(): void
    {
        // 1. Guest
        $this->getJson('/api/admin/batches')->assertStatus(401);
        $this->getJson('/api/admin/batches/stats')->assertStatus(401);
        $this->postJson('/api/admin/batches', [])->assertStatus(401);

        // 2. Student
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        $this->getJson('/api/admin/batches')->assertStatus(403);
        $this->getJson('/api/admin/batches/stats')->assertStatus(403);
        $this->postJson('/api/admin/batches', [])->assertStatus(403);

        // 3. Tutor
        $tutor = User::factory()->create(['role' => 'tutor']);
        Sanctum::actingAs($tutor);

        $this->getJson('/api/admin/batches')->assertStatus(403);
        $this->getJson('/api/admin/batches/stats')->assertStatus(403);
        $this->postJson('/api/admin/batches', [])->assertStatus(403);
    }

    public function test_automatic_rit_course_code_bc_ddmmyy_generation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tutor = User::factory()->create(['role' => 'tutor', 'name' => 'Professor Alan']);

        $course = $this->createCourse([
            'title' => 'Full Stack Cloud Development',
            'code' => 'FSCD',
            'category' => 'Cloud',
        ]);

        Sanctum::actingAs($admin);

        // Create batch starting on 2026-08-25 (DD=25, MM=08, YY=26 -> 250826)
        $res = $this->postJson('/api/admin/batches', [
            'name' => 'Full Stack Cloud Aug Morning Batch',
            'course_id' => $course->id,
            'tutor_id' => $tutor->id,
            'start_date' => '2026-08-25',
            'schedule_type' => 'weekdays',
            'schedule_time' => '09:00 AM - 11:00 AM',
            'max_students' => 30,
        ]);

        $res->assertStatus(201);
        $this->assertEquals('RIT(FSCD)BC250826', $res->json('batch.code'));

        $this->assertDatabaseHas('batches', [
            'name' => 'Full Stack Cloud Aug Morning Batch',
            'code' => 'RIT(FSCD)BC250826',
            'course_id' => $course->id,
            'tutor_id' => $tutor->id,
            'start_date' => '2026-08-25',
            'schedule_time' => '09:00 AM - 11:00 AM',
        ]);

        // Example 1: Artificial Intelligence + 23/08/2026 -> RIT(AI)BC230826
        $aiCourse = $this->createCourse([
            'title' => 'Artificial Intelligence',
            'code' => null, // Test automatic title derivation
        ]);
        $resAi1 = $this->postJson('/api/admin/batches', [
            'name' => 'AI Batch 1',
            'course_id' => $aiCourse->id,
            'start_date' => '2026-08-23',
        ]);
        $resAi1->assertStatus(201);
        $this->assertEquals('RIT(AI)BC230826', $resAi1->json('batch.code'));

        // Example 2: Artificial Intelligence + 30/08/2026 -> RIT(AI)BC300826
        $resAi2 = $this->postJson('/api/admin/batches', [
            'name' => 'AI Batch 2',
            'course_id' => $aiCourse->id,
            'start_date' => '2026-08-30',
        ]);
        $resAi2->assertStatus(201);
        $this->assertEquals('RIT(AI)BC300826', $resAi2->json('batch.code'));

        // Example 3: Python + 01/09/2026 -> RIT(PY)BC010926
        $pyCourse = $this->createCourse([
            'title' => 'Python',
            'code' => null,
        ]);
        $resPy = $this->postJson('/api/admin/batches', [
            'name' => 'Python Batch',
            'course_id' => $pyCourse->id,
            'start_date' => '2026-09-01',
        ]);
        $resPy->assertStatus(201);
        $this->assertEquals('RIT(PY)BC010926', $resPy->json('batch.code'));

        // Test creating batch without any name provided (only course and start_date)
        $resNoName = $this->postJson('/api/admin/batches', [
            'course_id' => $aiCourse->id,
            'start_date' => '2026-09-10',
        ]);
        $resNoName->assertStatus(201);
        $this->assertEquals('RIT(AI)BC100926', $resNoName->json('batch.code'));
        $this->assertEquals('RIT(AI)BC100926', $resNoName->json('batch.name'));
    }

    public function test_six_digit_date_search_matches_batches_by_ddmmyy(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->createCourse(['title' => 'Data Science AI', 'code' => 'DSAI']);

        // Batch starting on 2026-09-15 -> DDMMYY = 150926
        $batch1 = Batch::create([
            'name' => 'Data Science Sept Cohort',
            'code' => 'RIT(DSAI)BC150926',
            'course_id' => $course->id,
            'start_date' => '2026-09-15',
            'status' => 'upcoming',
        ]);

        // Batch starting on 2026-10-01 -> DDMMYY = 011026
        $batch2 = Batch::create([
            'name' => 'Data Science Oct Cohort',
            'code' => 'RIT(DSAI)BC011026',
            'course_id' => $course->id,
            'start_date' => '2026-10-01',
            'status' => 'upcoming',
        ]);

        Sanctum::actingAs($admin);

        // Search with 6-digit date: 150926
        $res = $this->getJson('/api/admin/batches?search=150926');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals($batch1->id, $res->json('data.0.id'));

        // Search with 6-digit date: 011026
        $res2 = $this->getJson('/api/admin/batches?search=011026');
        $res2->assertStatus(200);
        $this->assertCount(1, $res2->json('data'));
        $this->assertEquals($batch2->id, $res2->json('data.0.id'));
    }

    public function test_technology_and_course_and_status_filtering(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $reactCourse = $this->createCourse(['title' => 'React Architecture', 'category' => 'Frontend', 'code' => 'REACT']);
        $pythonCourse = $this->createCourse(['title' => 'Python Analytics', 'category' => 'Data Science', 'code' => 'PY']);

        $batch1 = Batch::create([
            'name' => 'React Weekend Batch',
            'code' => 'RIT(REACT)BC010926',
            'course_id' => $reactCourse->id,
            'start_date' => '2026-09-01',
            'status' => 'ongoing',
        ]);

        $batch2 = Batch::create([
            'name' => 'Python Morning Batch',
            'code' => 'RIT(PY)BC050926',
            'course_id' => $pythonCourse->id,
            'start_date' => '2026-09-05',
            'status' => 'upcoming',
        ]);

        Sanctum::actingAs($admin);

        // Filter by course
        $resCourse = $this->getJson("/api/admin/batches?course_id={$reactCourse->id}");
        $resCourse->assertStatus(200);
        $this->assertCount(1, $resCourse->json('data'));
        $this->assertEquals($batch1->id, $resCourse->json('data.0.id'));

        // Filter by status
        $resStatus = $this->getJson('/api/admin/batches?status=upcoming');
        $resStatus->assertStatus(200);
        $this->assertCount(1, $resStatus->json('data'));
        $this->assertEquals($batch2->id, $resStatus->json('data.0.id'));
    }

    public function test_admin_can_add_student_to_batch_and_ensures_course_enrollment(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student', 'name' => 'John Doe']);
        $course = $this->createCourse(['title' => 'DevOps Cloud', 'code' => 'DEVOPS']);

        $batch = Batch::create([
            'name' => 'DevOps Morning Batch',
            'code' => 'RIT(DEVOPS)BC200826',
            'course_id' => $course->id,
            'start_date' => '2026-08-20',
            'status' => 'ongoing',
        ]);

        Sanctum::actingAs($admin);

        $res = $this->postJson("/api/admin/batches/{$batch->id}/students", [
            'user_id' => $student->id,
            'notes' => 'Direct batch enrollment by counselor',
            'override_reason' => 'Finance-verified offline fees receipt for batch admission.',
        ]);

        $res->assertStatus(201)
            ->assertJsonFragment([
                'message' => 'Student enrolled into batch successfully.',
            ]);

        // Verify batch membership
        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $batch->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);

        // Verify parent course enrollment was auto-created for LMS dashboard
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        // Verify batch transfer log recorded
        $this->assertDatabaseHas('batch_transfers', [
            'user_id' => $student->id,
            'to_batch_id' => $batch->id,
            'action_type' => 'enrolled',
        ]);
    }

    public function test_batch_transfer_workflow_preserves_complete_historical_membership(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student', 'name' => 'Alice Walker']);
        $course = $this->createCourse(['title' => 'SAP S/4HANA', 'code' => 'SAP']);

        $batchA = Batch::create([
            'name' => 'SAP Morning Batch A',
            'code' => 'RIT(SAP)BC100826',
            'course_id' => $course->id,
            'start_date' => '2026-08-10',
            'status' => 'ongoing',
        ]);

        $batchB = Batch::create([
            'name' => 'SAP Evening Batch B',
            'code' => 'RIT(SAP)BC250826',
            'course_id' => $course->id,
            'start_date' => '2026-08-25',
            'status' => 'upcoming',
        ]);

        // Student initially active in Batch A with verified LMS access.
        $membershipA = BatchStudent::create([
            'batch_id' => $batchA->id,
            'user_id' => $student->id,
            'status' => 'active',
            'joined_at' => now()->subDays(10),
        ]);

        \App\Models\CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now()->subDays(10),
        ]);

        Sanctum::actingAs($admin);

        // Execute batch transfer from A to B
        $res = $this->postJson("/api/admin/batches/{$batchA->id}/students/{$student->id}/transfer", [
            'to_batch_id' => $batchB->id,
            'reason' => 'Student requested shift to evening batch due to work timing change',
        ]);

        $res->assertStatus(200);

        // Verify Batch A membership changed to 'transferred' with left_at set (historical record preserved!)
        $this->assertEquals('transferred', $membershipA->fresh()->status);
        $this->assertNotNull($membershipA->fresh()->left_at);

        // Verify Batch B active membership created
        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $batchB->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);

        // Verify total historical memberships for student in both batches is 2
        $this->assertEquals(2, BatchStudent::where('user_id', $student->id)->count());

        // Verify transfer audit log
        $this->assertDatabaseHas('batch_transfers', [
            'user_id' => $student->id,
            'from_batch_id' => $batchA->id,
            'to_batch_id' => $batchB->id,
            'action_type' => 'transferred',
            'reason' => 'Student requested shift to evening batch due to work timing change',
        ]);
    }

    public function test_discontinuation_and_rejoin_workflow(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student', 'name' => 'Michael Scott']);
        $course = $this->createCourse(['title' => 'Kubernetes Administration', 'code' => 'CKA']);

        $batch1 = Batch::create([
            'name' => 'CKA Batch 1',
            'code' => 'RIT(CKA)BC010826',
            'course_id' => $course->id,
            'start_date' => '2026-08-01',
            'status' => 'ongoing',
        ]);

        $batch2 = Batch::create([
            'name' => 'CKA Batch 2',
            'code' => 'RIT(CKA)BC011026',
            'course_id' => $course->id,
            'start_date' => '2026-10-01',
            'status' => 'upcoming',
        ]);

        $membership = BatchStudent::create([
            'batch_id' => $batch1->id,
            'user_id' => $student->id,
            'status' => 'active',
            'joined_at' => now()->subDays(20),
        ]);

        \App\Models\CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now()->subDays(20),
        ]);

        Sanctum::actingAs($admin);

        // 1. Discontinue student
        $resDiscontinue = $this->postJson("/api/admin/batches/{$batch1->id}/students/{$student->id}/discontinue", [
            'reason' => 'Medical leave for 4 weeks',
        ]);
        $resDiscontinue->assertStatus(200);

        $this->assertEquals('discontinued', $membership->fresh()->status);
        $this->assertNotNull($membership->fresh()->discontinued_at);
        $this->assertEquals('Medical leave for 4 weeks', $membership->fresh()->discontinuation_reason);

        // 2. Rejoin student into new Batch 2
        $resRejoin = $this->postJson("/api/admin/batches/{$batch1->id}/students/{$student->id}/rejoin", [
            'target_batch_id' => $batch2->id,
            'reason' => 'Recovered and resuming with next cohort',
        ]);
        $resRejoin->assertStatus(200);

        // Verify active membership in Batch 2
        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $batch2->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);

        // Verify audit log has both discontinued and rejoined records
        $this->assertDatabaseHas('batch_transfers', [
            'user_id' => $student->id,
            'from_batch_id' => $batch1->id,
            'action_type' => 'discontinued',
        ]);

        $this->assertDatabaseHas('batch_transfers', [
            'user_id' => $student->id,
            'from_batch_id' => $batch1->id,
            'to_batch_id' => $batch2->id,
            'action_type' => 'rejoined',
        ]);
    }

    public function test_unique_batch_code_and_collision_resolution(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $aiCourse = $this->createCourse(['title' => 'Artificial Intelligence', 'code' => 'AI']);
        $javaCourse = $this->createCourse(['title' => 'Java', 'code' => 'JAVA']);
        $fullStackCourse = $this->createCourse(['title' => 'Full Stack Development', 'code' => 'FSD']);

        Sanctum::actingAs($admin);

        // 1. AI Batch starting on 23-08-2026 -> RIT(AI)BC230826
        $resAi = $this->postJson('/api/admin/batches', [
            'course_id' => $aiCourse->id,
            'start_date' => '2026-08-23',
        ]);
        $resAi->assertStatus(201);
        $this->assertEquals('RIT(AI)BC230826', $resAi->json('batch.code'));

        // 2. Creating another batch with same course and date auto-resolves collision with -02 suffix
        $resAiCollision = $this->postJson('/api/admin/batches', [
            'course_id' => $aiCourse->id,
            'start_date' => '2026-08-23',
        ]);
        $resAiCollision->assertStatus(201);
        $this->assertEquals('RIT(AI)BC230826-02', $resAiCollision->json('batch.code'));

        // 3. Java Batch starting on 23-08-2026 -> RIT(JAVA)BC230826
        $resJava = $this->postJson('/api/admin/batches', [
            'course_id' => $javaCourse->id,
            'start_date' => '2026-08-23',
        ]);
        $resJava->assertStatus(201);
        $this->assertEquals('RIT(JAVA)BC230826', $resJava->json('batch.code'));

        // 4. Full Stack Batch starting on 23-08-2026 -> RIT(FSD)BC230826
        $resFs = $this->postJson('/api/admin/batches', [
            'course_id' => $fullStackCourse->id,
            'start_date' => '2026-08-23',
        ]);
        $resFs->assertStatus(201);
        $this->assertEquals('RIT(FSD)BC230826', $resFs->json('batch.code'));
    }

    public function test_duplicate_active_enrollment_in_same_batch_is_prevented(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student', 'name' => 'Sara Connor']);
        $course = $this->createCourse(['title' => 'Python Fullstack', 'code' => 'PYFS']);

        $batch = Batch::create([
            'name' => 'Python Aug Batch',
            'code' => 'RIT(PYFS)BC230826',
            'course_id' => $course->id,
            'start_date' => '2026-08-23',
            'status' => 'ongoing',
        ]);

        Sanctum::actingAs($admin);

        // 1. Initial enrollment succeeds (B3: admin override for verified admission)
        $res1 = $this->postJson("/api/admin/batches/{$batch->id}/students", [
            'user_id' => $student->id,
            'notes' => 'First admission',
            'override_reason' => 'Finance-verified offline fees receipt for batch admission.',
        ]);
        $res1->assertStatus(201);

        // 2. Duplicate active enrollment attempt fails with 422
        $res2 = $this->postJson("/api/admin/batches/{$batch->id}/students", [
            'user_id' => $student->id,
            'notes' => 'Duplicate admission attempt',
        ]);
        $res2->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Student is already actively enrolled in this batch.',
            ]);

        // Verify only 1 active membership exists
        $this->assertEquals(1, BatchStudent::where('batch_id', $batch->id)->where('user_id', $student->id)->where('status', 'active')->count());
    }

    public function test_batch_search_with_six_digits_and_technology_disambiguation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $aiCourse = $this->createCourse(['title' => 'Artificial Intelligence', 'code' => 'AI']);
        $pythonCourse = $this->createCourse(['title' => 'Python Programming', 'code' => 'PY']);
        $javaCourse = $this->createCourse(['title' => 'Java Enterprise', 'code' => 'JAVA']);

        // 3 Batches starting on 23-08-2026 (DDMMYY = 230826)
        $aiBatch = Batch::create([
            'name' => 'AI Aug Cohort',
            'code' => 'RIT(AI)BC230826',
            'course_id' => $aiCourse->id,
            'start_date' => '2026-08-23',
            'status' => 'upcoming',
        ]);

        $pyBatch = Batch::create([
            'name' => 'Python Aug Cohort',
            'code' => 'RIT(PY)BC230826',
            'course_id' => $pythonCourse->id,
            'start_date' => '2026-08-23',
            'status' => 'upcoming',
        ]);

        $javaBatch = Batch::create([
            'name' => 'Java Aug Cohort',
            'code' => 'RIT(JAVA)BC230826',
            'course_id' => $javaCourse->id,
            'start_date' => '2026-08-23',
            'status' => 'upcoming',
        ]);

        Sanctum::actingAs($admin);

        // Search with 6 digits: 230826 returns all 3 cohorts on that date
        $resDateAll = $this->getJson('/api/admin/batches?search=230826');
        $resDateAll->assertStatus(200);
        $this->assertCount(3, $resDateAll->json('data'));

        // Search with 6 digits: 230826 + course_id (technology selection: AI)
        $resAi = $this->getJson("/api/admin/batches?search=230826&course_id={$aiCourse->id}");
        $resAi->assertStatus(200);
        $this->assertCount(1, $resAi->json('data'));
        $this->assertEquals($aiBatch->id, $resAi->json('data.0.id'));
        $this->assertEquals('RIT(AI)BC230826', $resAi->json('data.0.code'));

        // Search with 6 digits: 230826 + course_id (technology selection: Java)
        $resJava = $this->getJson("/api/admin/batches?search=230826&course_id={$javaCourse->id}");
        $resJava->assertStatus(200);
        $this->assertCount(1, $resJava->json('data'));
        $this->assertEquals($javaBatch->id, $resJava->json('data.0.id'));
        $this->assertEquals('RIT(JAVA)BC230826', $resJava->json('data.0.code'));
    }

    public function test_single_active_session_is_enforced_on_batch_management(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'current_session_id' => 'device_session_current',
        ]);

        // Expired/superseded token
        $revokedToken = $admin->createToken('revoked', ['session:device_session_old'])->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer ' . $revokedToken)
            ->getJson('/api/admin/batches');

        $res->assertStatus(401)
            ->assertJsonFragment([
                'code' => 'SESSION_REVOKED',
            ]);
    }

    public function test_rejoin_after_transfer_does_not_create_duplicate_active_membership(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student', 'name' => 'Diana Prince']);
        $course = $this->createCourse(['title' => 'AWS Certified Solutions', 'code' => 'AWS']);

        $batchA = Batch::create([
            'name' => 'AWS Morning Batch A',
            'code' => 'RIT(AWS)BC010826',
            'course_id' => $course->id,
            'start_date' => '2026-08-01',
            'status' => 'ongoing',
        ]);

        $batchB = Batch::create([
            'name' => 'AWS Evening Batch B',
            'code' => 'RIT(AWS)BC011026',
            'course_id' => $course->id,
            'start_date' => '2026-10-01',
            'status' => 'upcoming',
        ]);

        // Student starts active in Batch A with verified LMS access.
        BatchStudent::create([
            'batch_id' => $batchA->id,
            'user_id' => $student->id,
            'status' => 'active',
            'joined_at' => now()->subDays(5),
        ]);

        \App\Models\CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now()->subDays(5),
        ]);

        Sanctum::actingAs($admin);

        // 1. Transfer A -> B (A becomes transferred, B becomes active)
        $this->postJson("/api/admin/batches/{$batchA->id}/students/{$student->id}/transfer", [
            'to_batch_id' => $batchB->id,
            'reason' => 'Shift to evening batch',
        ])->assertStatus(200);

        // 2. Discontinue from B so the B membership becomes non-active
        $this->postJson("/api/admin/batches/{$batchB->id}/students/{$student->id}/discontinue", [
            'reason' => 'Temporary pause',
        ])->assertStatus(200);

        // 3. Rejoin from A targeting B (cross-batch rejoin) - must reuse the
        //    existing B membership and NOT create a duplicate active record.
        $this->postJson("/api/admin/batches/{$batchA->id}/students/{$student->id}/rejoin", [
            'target_batch_id' => $batchB->id,
            'reason' => 'Resuming studies',
        ])->assertStatus(200);

        $activeInB = BatchStudent::where('batch_id', $batchB->id)
            ->where('user_id', $student->id)
            ->where('status', 'active')
            ->count();

        $totalInB = BatchStudent::where('batch_id', $batchB->id)
            ->where('user_id', $student->id)
            ->count();

        $this->assertEquals(1, $activeInB, 'There must be exactly one active membership in the target batch.');
        $this->assertEquals(1, $totalInB, 'Rejoin must reuse the existing membership rather than insert a duplicate.');
    }

    public function test_transfer_and_rejoin_reject_over_capacity_batch(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $studentA = User::factory()->create(['role' => 'student', 'name' => 'Bruce Wayne']);
        $studentB = User::factory()->create(['role' => 'student', 'name' => 'Clark Kent']);
        $course = $this->createCourse(['title' => 'Terraform Automation', 'code' => 'TF']);

        $batchA = Batch::create([
            'name' => 'TF Morning Batch',
            'code' => 'RIT(TF)BC010826',
            'course_id' => $course->id,
            'start_date' => '2026-08-01',
            'status' => 'ongoing',
        ]);

        // Batch B has capacity for exactly 1 student and is already full
        $batchB = Batch::create([
            'name' => 'TF Evening Batch',
            'code' => 'RIT(TF)BC011026',
            'course_id' => $course->id,
            'start_date' => '2026-10-01',
            'status' => 'upcoming',
            'max_students' => 1,
        ]);

        BatchStudent::create([
            'batch_id' => $batchA->id,
            'user_id' => $studentA->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Fill Batch B to capacity
        BatchStudent::create([
            'batch_id' => $batchB->id,
            'user_id' => $studentB->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        // Transfer into a full destination batch is rejected
        $this->postJson("/api/admin/batches/{$batchA->id}/students/{$studentA->id}/transfer", [
            'to_batch_id' => $batchB->id,
            'reason' => 'Move to evening batch',
        ])->assertStatus(422);

        // Rejoin into a full target batch is rejected
        $this->postJson("/api/admin/batches/{$batchA->id}/students/{$studentA->id}/rejoin", [
            'target_batch_id' => $batchB->id,
            'reason' => 'Resume in evening batch',
        ])->assertStatus(422);

        // No duplicate / unintended active membership was created in Batch B
        $activeInB = BatchStudent::where('batch_id', $batchB->id)
            ->where('status', 'active')
            ->count();
        $this->assertEquals(1, $activeInB);
    }

    public function test_transfer_and_rejoin_reject_completed_or_cancelled_batch(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student', 'name' => 'Tony Stark']);
        $course = $this->createCourse(['title' => 'Cybersecurity Essentials', 'code' => 'CSE']);

        $batchA = Batch::create([
            'name' => 'CSE Active Batch',
            'code' => 'RIT(CSE)BC010826',
            'course_id' => $course->id,
            'start_date' => '2026-08-01',
            'status' => 'ongoing',
        ]);

        $completedBatch = Batch::create([
            'name' => 'CSE Completed Cohort',
            'code' => 'RIT(CSE)BC101026',
            'course_id' => $course->id,
            'start_date' => '2026-10-10',
            'status' => 'completed',
        ]);

        $cancelledBatch = Batch::create([
            'name' => 'CSE Cancelled Cohort',
            'code' => 'RIT(CSE)BC201026',
            'course_id' => $course->id,
            'start_date' => '2026-10-20',
            'status' => 'cancelled',
        ]);

        BatchStudent::create([
            'batch_id' => $batchA->id,
            'user_id' => $student->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        // Transfer into a completed destination batch is rejected
        $this->postJson("/api/admin/batches/{$batchA->id}/students/{$student->id}/transfer", [
            'to_batch_id' => $completedBatch->id,
            'reason' => 'Wrong move',
        ])->assertStatus(422);

        // Rejoin into a cancelled target batch is rejected
        $this->postJson("/api/admin/batches/{$batchA->id}/students/{$student->id}/rejoin", [
            'target_batch_id' => $cancelledBatch->id,
            'reason' => 'Wrong resume',
        ])->assertStatus(422);

        // Transfer into a cancelled target batch is also rejected
        $this->postJson("/api/admin/batches/{$batchA->id}/students/{$student->id}/transfer", [
            'to_batch_id' => $cancelledBatch->id,
            'reason' => 'Wrong move to cancelled',
        ])->assertStatus(422);

        // Student remains active only in the original batch
        $this->assertDatabaseHas('batch_students', [
            'batch_id' => $batchA->id,
            'user_id' => $student->id,
            'status' => 'active',
        ]);
    }

    public function test_remove_student_only_deactivates_active_membership_preserves_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student', 'name' => 'Natasha Romanoff']);
        $course = $this->createCourse(['title' => 'Node.js Backend', 'code' => 'NODE']);

        $batch = Batch::create([
            'name' => 'Node Aug Batch',
            'code' => 'RIT(NODE)BC230826',
            'course_id' => $course->id,
            'start_date' => '2026-08-23',
            'status' => 'ongoing',
        ]);

        // Historical membership (must be preserved)
        $historical = BatchStudent::create([
            'batch_id' => $batch->id,
            'user_id' => $student->id,
            'status' => 'transferred',
            'joined_at' => now()->subDays(20),
            'left_at' => now()->subDays(10),
        ]);

        // Current active membership (should be deactivated only)
        $active = BatchStudent::create([
            'batch_id' => $batch->id,
            'user_id' => $student->id,
            'status' => 'active',
            'joined_at' => now()->subDays(5),
        ]);

        Sanctum::actingAs($admin);

        $res = $this->deleteJson("/api/admin/batches/{$batch->id}/students/{$student->id}");
        $res->assertStatus(200);

        // Historical record is preserved / untouched
        $this->assertEquals('transferred', $historical->fresh()->status);

        // Active membership is deactivated (not hard-deleted)
        $this->assertEquals('removed', $active->fresh()->status);
        $this->assertNotNull($active->fresh()->left_at);

        // Both membership rows still exist (nothing was hard-deleted)
        $this->assertEquals(2, BatchStudent::where('batch_id', $batch->id)
            ->where('user_id', $student->id)
            ->count());
    }
}
