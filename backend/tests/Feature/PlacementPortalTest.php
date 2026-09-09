<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\PlacementApplication;
use App\Models\PlacementOpportunity;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlacementPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\PlacementSettingService::updateSettings(['mock_interview_required' => false]);
    }

    private function createCourse(array $attributes = []): Course
    {
        $title = $attributes['title'] ?? 'Course ' . Str::random(5);
        return Course::create(array_merge([
            'title' => $title,
            'slug' => Str::slug($title),
            'code' => $attributes['code'] ?? 'AI',
            'description' => 'Course description for placement portal testing.',
            'category' => 'Engineering',
            'instructor' => 'Lead Architect',
            'duration' => '12 weeks',
            'difficulty' => 'Advanced',
            'is_published' => true,
        ], $attributes));
    }

    private function createBatch(Course $course, array $attributes = []): Batch
    {
        return Batch::create(array_merge([
            'name' => 'AI September Batch',
            'code' => 'RIT(AI)BC010926',
            'course_id' => $course->id,
            'start_date' => '2026-09-01',
            'status' => 'upcoming',
            'max_students' => 30,
        ], $attributes));
    }

    private function createOpportunity(array $attributes = []): PlacementOpportunity
    {
        return PlacementOpportunity::create(array_merge([
            'title' => 'Cloud Native Software Engineer',
            'company_name' => 'TechCorp Global',
            'location' => 'Hyderabad / Hybrid',
            'employment_type' => 'Full-time',
            'salary_package' => '10.0 - 14.0 LPA',
            'experience_required' => '0 - 2 Years',
            'description' => 'Build microservices with Kubernetes, Golang, and React.',
            'status' => PlacementOpportunity::STATUS_PUBLISHED,
            'deadline_date' => Carbon::today()->addDays(30),
        ], $attributes));
    }

    public function test_public_opportunities_listing_and_filtering(): void
    {
        $published = $this->createOpportunity(['title' => 'Backend Engineer']);
        $draft = $this->createOpportunity([
            'title' => 'Internal Draft Job',
            'status' => PlacementOpportunity::STATUS_DRAFT,
        ]);
        $closed = $this->createOpportunity([
            'title' => 'Archived Role',
            'status' => PlacementOpportunity::STATUS_CLOSED,
        ]);

        // Public request
        $res = $this->getJson('/api/placements/opportunities');
        $res->assertStatus(200);

        $titles = collect($res->json('data'))->pluck('title')->toArray();
        $this->assertContains('Backend Engineer', $titles);
        $this->assertNotContains('Internal Draft Job', $titles);
        $this->assertNotContains('Archived Role', $titles);
    }

    public function test_verified_student_profile_prefill(): void
    {
        $course = $this->createCourse(['title' => 'Data Engineering']);
        $batch = $this->createBatch($course, ['code' => 'RIT(DE)BC150926']);

        $student = User::factory()->create([
            'name' => 'Aravind Kumar',
            'email' => 'aravind@example.com',
            'phone' => '+91 9988776655',
            'student_id' => 'STU-1088',
            'role' => 'student',
        ]);

        // Enroll student in course and batch
        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        BatchStudent::create([
            'user_id' => $student->id,
            'batch_id' => $batch->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($student);

        $res = $this->getJson('/api/placements/profile-prefill');
        $res->assertStatus(200);

        $res->assertJsonFragment([
            'student_name' => 'Aravind Kumar',
            'email' => 'aravind@example.com',
            'phone' => '+91 9988776655',
            'student_id' => 'STU-1088',
        ]);

        $batchCodes = collect($res->json('active_batches'))->pluck('code')->toArray();
        $this->assertContains('RIT(DE)BC150926', $batchCodes);
    }

    public function test_valid_placement_application_workflow(): void
    {
        $course = $this->createCourse(['title' => 'Full Stack AI']);
        $batch = $this->createBatch($course, ['code' => 'RIT(AI)BC230826']);
        $opportunity = $this->createOpportunity(['title' => 'Junior AI Developer']);

        $student = User::factory()->create([
            'name' => 'Priya Sharma',
            'email' => 'priya@example.com',
            'phone' => '9876543210',
            'role' => 'student',
        ]);

        Sanctum::actingAs($student);

        // Submit valid application with existing batch code
        $res = $this->postJson("/api/placements/opportunities/{$opportunity->id}/apply", [
            'batch_number' => 'RIT(AI)BC230826',
            'phone' => '9876543210',
            'course_id' => $course->id,
            'resume_url' => 'https://example.com/resumes/priya_sharma_cv.pdf',
            'cover_note' => 'I have built multiple full stack apps with React and PyTorch.',
        ]);

        $res->assertStatus(201);
        $res->assertJsonFragment([
            'student_name' => 'Priya Sharma',
            'email' => 'priya@example.com',
            'batch_code' => 'RIT(AI)BC230826',
            'status' => 'applied',
        ]);

        $this->assertDatabaseHas('placement_applications', [
            'placement_opportunity_id' => $opportunity->id,
            'user_id' => $student->id,
            'batch_id' => $batch->id,
            'batch_code' => 'RIT(AI)BC230826',
            'status' => 'applied',
        ]);
    }

    public function test_application_without_phone_stores_no_fabricated_contact(): void
    {
        $course = $this->createCourse(['title' => 'Full Stack AI']);
        $batch = $this->createBatch($course, ['code' => 'RIT(AI)BC230826']);
        $opportunity = $this->createOpportunity(['title' => 'Junior AI Developer']);

        $student = User::factory()->create([
            'name' => 'No Phone Student',
            'email' => 'nophone@example.com',
            'phone' => null,
            'role' => 'student',
        ]);

        Sanctum::actingAs($student);

        // No phone submitted and student has no phone on file -> must NOT fabricate a number.
        $res = $this->postJson("/api/placements/opportunities/{$opportunity->id}/apply", [
            'batch_number' => 'RIT(AI)BC230826',
            'course_id' => $course->id,
            'resume_url' => 'https://example.com/resumes/cv.pdf',
        ]);

        $res->assertStatus(201);

        $this->assertDatabaseHas('placement_applications', [
            'placement_opportunity_id' => $opportunity->id,
            'user_id' => $student->id,
            'phone' => '',
        ]);
        $this->assertSame('', PlacementApplication::where('user_id', $student->id)->value('phone'));
    }

    public function test_invalid_or_non_existent_batch_is_rejected(): void
    {
        $opportunity = $this->createOpportunity();
        $student = User::factory()->create(['role' => 'student']);

        Sanctum::actingAs($student);

        // Submit invalid batch code
        $res = $this->postJson("/api/placements/opportunities/{$opportunity->id}/apply", [
            'batch_number' => 'NON-EXISTENT-BATCH-999',
            'phone' => '9876543210',
            'resume_url' => 'https://example.com/cv.pdf',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['batch_number']);

        $this->assertEquals(0, PlacementApplication::count());
    }

    public function test_duplicate_application_prevention(): void
    {
        $course = $this->createCourse();
        $batch = $this->createBatch($course, ['code' => 'RIT(AI)BC230826']);
        $opportunity = $this->createOpportunity();

        $student = User::factory()->create(['role' => 'student']);

        Sanctum::actingAs($student);

        // 1st Application -> Success
        $res1 = $this->postJson("/api/placements/opportunities/{$opportunity->id}/apply", [
            'batch_id' => $batch->id,
            'phone' => '9876543210',
            'resume_url' => 'https://example.com/cv.pdf',
        ]);
        $res1->assertStatus(201);

        // 2nd Application for same opportunity -> 422 Duplicate rejection
        $res2 = $this->postJson("/api/placements/opportunities/{$opportunity->id}/apply", [
            'batch_id' => $batch->id,
            'phone' => '9876543210',
            'resume_url' => 'https://example.com/cv.pdf',
        ]);

        $res2->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'You have already submitted an application for this opportunity.',
            ]);

        $this->assertEquals(1, PlacementApplication::where('user_id', $student->id)->count());
    }

    public function test_unauthenticated_user_cannot_apply_or_view_applications(): void
    {
        $opportunity = $this->createOpportunity();

        $this->postJson("/api/placements/opportunities/{$opportunity->id}/apply", [
            'batch_number' => 'RIT(AI)BC230826',
            'phone' => '9876543210',
            'resume_url' => 'https://example.com/cv.pdf',
        ])->assertStatus(401);

        $this->getJson('/api/placements/my-applications')->assertStatus(401);
        $this->getJson('/api/placements/profile-prefill')->assertStatus(401);
    }

    public function test_student_only_views_their_own_applications(): void
    {
        $course = $this->createCourse();
        $batch = $this->createBatch($course);
        $opportunity1 = $this->createOpportunity(['title' => 'Role for Alice']);
        $opportunity2 = $this->createOpportunity(['title' => 'Role for Bob']);

        $alice = User::factory()->create(['role' => 'student', 'name' => 'Alice']);
        $bob = User::factory()->create(['role' => 'student', 'name' => 'Bob']);

        PlacementApplication::create([
            'placement_opportunity_id' => $opportunity1->id,
            'user_id' => $alice->id,
            'batch_id' => $batch->id,
            'batch_code' => $batch->code,
            'student_name' => 'Alice',
            'email' => 'alice@test.com',
            'phone' => '1111111111',
            'resume_url' => 'https://example.com/alice.pdf',
            'status' => 'applied',
            'applied_at' => now(),
        ]);

        PlacementApplication::create([
            'placement_opportunity_id' => $opportunity2->id,
            'user_id' => $bob->id,
            'batch_id' => $batch->id,
            'batch_code' => $batch->code,
            'student_name' => 'Bob',
            'email' => 'bob@test.com',
            'phone' => '2222222222',
            'resume_url' => 'https://example.com/bob.pdf',
            'status' => 'applied',
            'applied_at' => now(),
        ]);

        // Alice views my-applications
        Sanctum::actingAs($alice);
        $resAlice = $this->getJson('/api/placements/my-applications');
        $resAlice->assertStatus(200);

        $aliceAppTitles = collect($resAlice->json())->pluck('opportunity.title')->toArray();
        $this->assertContains('Role for Alice', $aliceAppTitles);
        $this->assertNotContains('Role for Bob', $aliceAppTitles);

        // Bob views my-applications
        Sanctum::actingAs($bob);
        $resBob = $this->getJson('/api/placements/my-applications');
        $resBob->assertStatus(200);

        $bobAppTitles = collect($resBob->json())->pluck('opportunity.title')->toArray();
        $this->assertContains('Role for Bob', $bobAppTitles);
        $this->assertNotContains('Role for Alice', $bobAppTitles);
    }

    public function test_student_cannot_modify_administrative_application_status(): void
    {
        $course = $this->createCourse();
        $batch = $this->createBatch($course);
        $opportunity = $this->createOpportunity();
        $student = User::factory()->create(['role' => 'student']);

        $application = PlacementApplication::create([
            'placement_opportunity_id' => $opportunity->id,
            'user_id' => $student->id,
            'batch_id' => $batch->id,
            'batch_code' => $batch->code,
            'student_name' => 'Student Test',
            'email' => 'test@student.com',
            'phone' => '9988776655',
            'resume_url' => 'https://example.com/cv.pdf',
            'status' => 'applied',
            'applied_at' => now(),
        ]);

        Sanctum::actingAs($student);

        // Attempting to change status as student -> 403 Forbidden
        $res = $this->putJson("/api/admin/placements/applications/{$application->id}/status", [
            'status' => 'selected',
        ]);
        $res->assertStatus(403);

        $this->assertEquals('applied', $application->fresh()->status);
    }

    public function test_admin_placement_management_and_status_lifecycle(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Placement Officer']);
        $course = $this->createCourse(['title' => 'DevOps Cloud']);
        $batch = $this->createBatch($course, ['code' => 'RIT(DEVOPS)BC011026']);
        $student = User::factory()->create(['role' => 'student', 'name' => 'Candidate Rahul']);

        Sanctum::actingAs($admin);

        // 1. Admin creates placement opportunity
        $resCreateOpp = $this->postJson('/api/admin/placements/opportunities', [
            'title' => 'DevOps Automation Specialist',
            'company_name' => 'Acme Cloud Solutions',
            'location' => 'Bangalore / Hybrid',
            'employment_type' => 'Full-time',
            'salary_package' => '12.0 - 16.0 LPA',
            'experience_required' => '1 - 3 Years',
            'skills_required' => ['Terraform', 'Kubernetes', 'CI/CD', 'AWS'],
            'description' => 'Manage infrastructure as code and automated deployment pipelines.',
            'openings_count' => 3,
            'deadline_date' => Carbon::today()->addDays(45)->toDateString(),
            'status' => 'published',
        ]);

        $resCreateOpp->assertStatus(201);
        $oppId = $resCreateOpp->json('opportunity.id');

        // 2. Student submits application
        Sanctum::actingAs($student);
        $this->postJson("/api/placements/opportunities/{$oppId}/apply", [
            'batch_id' => $batch->id,
            'phone' => '+91 9123456780',
            'resume_url' => 'https://example.com/rahul_devops.pdf',
            'cover_note' => 'Certified Kubernetes Administrator with AWS experience.',
        ])->assertStatus(201);

        $application = PlacementApplication::where('user_id', $student->id)->first();

        // 3. Admin views stats and applications roster
        Sanctum::actingAs($admin);

        $resStats = $this->getJson('/api/admin/placements/stats');
        $resStats->assertStatus(200);
        $this->assertEquals(1, $resStats->json('total_opportunities'));
        $this->assertEquals(1, $resStats->json('total_applications'));

        $resApps = $this->getJson('/api/admin/placements/applications');
        $resApps->assertStatus(200);
        $this->assertEquals(1, count($resApps->json('data')));

        // 4. Admin advances candidate to 'shortlisted'
        $resShortlist = $this->putJson("/api/admin/placements/applications/{$application->id}/status", [
            'status' => 'shortlisted',
            'admin_notes' => 'Resume screened. Good Kubernetes background.',
        ]);
        $resShortlist->assertStatus(200);
        $this->assertEquals('shortlisted', $application->fresh()->status);

        // 5. Admin advances to 'interview_scheduled'
        $interviewDate = Carbon::today()->addDays(3)->setTime(14, 0);
        $resInterview = $this->putJson("/api/admin/placements/applications/{$application->id}/status", [
            'status' => 'interview_scheduled',
            'interview_date' => $interviewDate->toISOString(),
            'interview_notes' => 'Technical round via Google Meet. Link sent via email.',
        ]);
        $resInterview->assertStatus(200);
        $this->assertEquals('interview_scheduled', $application->fresh()->status);

        // 6. Admin advances to 'selected'
        $resSelect = $this->putJson("/api/admin/placements/applications/{$application->id}/status", [
            'status' => 'selected',
            'admin_notes' => 'Candidate selected with offer letter for 14 LPA.',
        ]);
        $resSelect->assertStatus(200);
        $this->assertEquals('selected', $application->fresh()->status);

        // 7. Student checks 'my-applications' and sees selected status
        Sanctum::actingAs($student);
        $resStudentCheck = $this->getJson('/api/placements/my-applications');
        $resStudentCheck->assertStatus(200);
        $this->assertEquals('selected', $resStudentCheck->json('0.status'));
    }

    public function test_complete_end_to_end_placement_workflow_verification(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin Placement Cell']);
        $course = $this->createCourse(['title' => 'Master In AI & ML', 'code' => 'AI']);
        $batch = $this->createBatch($course, ['name' => 'AI Batch 2026', 'code' => 'RIT(AI)BC230826']);

        $studentA = User::factory()->create([
            'name' => 'Candidate Alpha',
            'email' => 'alpha@student.com',
            'phone' => '+91 9876543210',
            'student_id' => 'STU-ALPHA-01',
            'role' => 'student',
        ]);
        CourseEnrollment::create(['user_id' => $studentA->id, 'course_id' => $course->id, 'status' => 'active', 'enrolled_at' => now()]);
        BatchStudent::create(['user_id' => $studentA->id, 'batch_id' => $batch->id, 'status' => 'active', 'joined_at' => now()]);

        $studentB = User::factory()->create([
            'name' => 'Candidate Beta',
            'email' => 'beta@student.com',
            'phone' => '+91 9123456789',
            'student_id' => 'STU-BETA-02',
            'role' => 'student',
        ]);

        // Step 1-5: Admin creates opportunity with all required fields & publishes
        Sanctum::actingAs($admin);
        $resJob = $this->postJson('/api/admin/placements/opportunities', [
            'company_name' => 'Google Cloud Partner Inc',
            'title' => 'AI Solutions Engineer',
            'description' => 'Architect machine learning workflows and LLM applications on GCP.',
            'skills_required' => ['Python', 'PyTorch', 'Docker', 'Kubernetes', 'FastAPI'],
            'location' => 'Hyderabad / Hybrid',
            'employment_type' => 'Full-time',
            'salary_package' => '14.0 - 18.0 LPA',
            'experience_required' => '0 - 2 Years',
            'eligibility' => 'B.Tech / MCA / MasterInTech AI Cohort Graduates with 60%+',
            'selection_process' => '1. Online Assessment -> 2. Technical Live Coding -> 3. Fitment Round',
            'openings_count' => 5,
            'deadline_date' => Carbon::today()->addDays(20)->toDateString(),
            'status' => 'published',
            'is_featured' => true,
        ]);
        $resJob->assertStatus(201);
        $jobId = $resJob->json('opportunity.id');

        // Step 6: Published job appears in /placements under Active Opportunities
        $resPublic = $this->getJson('/api/placements/opportunities');
        $resPublic->assertStatus(200);
        $publicJobs = collect($resPublic->json('data'))->pluck('title')->toArray();
        $this->assertContains('AI Solutions Engineer', $publicJobs);

        // Step 7: Student opens job details
        $resJobDetail = $this->getJson("/api/placements/opportunities/{$jobId}");
        $resJobDetail->assertStatus(200);
        $this->assertEquals('Google Cloud Partner Inc', $resJobDetail->json('opportunity.company_name'));
        $this->assertEquals('B.Tech / MCA / MasterInTech AI Cohort Graduates with 60%+', $resJobDetail->json('opportunity.eligibility'));

        // Step 8-9: Authenticated Student automatically loads verified prefill data
        Sanctum::actingAs($studentA);
        $resPrefill = $this->getJson('/api/placements/profile-prefill');
        $resPrefill->assertStatus(200);
        $this->assertEquals('Candidate Alpha', $resPrefill->json('student_name'));
        $this->assertEquals('alpha@student.com', $resPrefill->json('email'));
        $this->assertEquals('+91 9876543210', $resPrefill->json('phone'));
        $this->assertEquals('RIT(AI)BC230826', $resPrefill->json('active_batches.0.code'));

        // Step 10: Validate student's batch against existing batch records (reject invalid)
        $resInvalidBatch = $this->postJson("/api/placements/opportunities/{$jobId}/apply", [
            'batch_number' => 'INVALID_NON_EXISTENT_BATCH_999',
            'phone' => '+91 9876543210',
            'resume_url' => 'https://storage.googleapis.com/resumes/alpha_cv.pdf',
        ]);
        $resInvalidBatch->assertStatus(422);
        $resInvalidBatch->assertJsonValidationErrors(['batch_number']);

        // Step 11: Student submits valid application
        $resApply = $this->postJson("/api/placements/opportunities/{$jobId}/apply", [
            'batch_number' => 'RIT(AI)BC230826',
            'phone' => '+91 9876543210',
            'resume_url' => 'https://storage.googleapis.com/resumes/alpha_cv.pdf',
            'cover_note' => 'Graduated from MasterInTech AI batch with 3 deployed applications.',
        ]);
        $resApply->assertStatus(201);
        $appId = $resApply->json('application.id');

        // Step 12: Prevent duplicate application to same job
        $resDuplicate = $this->postJson("/api/placements/opportunities/{$jobId}/apply", [
            'batch_number' => 'RIT(AI)BC230826',
            'phone' => '+91 9876543210',
            'resume_url' => 'https://storage.googleapis.com/resumes/alpha_cv.pdf',
        ]);
        $resDuplicate->assertStatus(422)
            ->assertJsonFragment(['message' => 'You have already submitted an application for this opportunity.']);

        // Step 13: Application appears in student's My Applications
        $resMyApps = $this->getJson('/api/placements/my-applications');
        $resMyApps->assertStatus(200);
        $this->assertCount(1, $resMyApps->json());
        $this->assertEquals('applied', $resMyApps->json('0.status'));
        $this->assertEquals('AI Solutions Engineer', $resMyApps->json('0.opportunity.title'));

        // Step 14: Admin views application in Admin Placement Desk
        Sanctum::actingAs($admin);
        $resAdminApps = $this->getJson("/api/admin/placements/applications?placement_opportunity_id={$jobId}");
        $resAdminApps->assertStatus(200);
        $this->assertCount(1, $resAdminApps->json('data'));
        $this->assertEquals('Candidate Alpha', $resAdminApps->json('data.0.student_name'));
        $this->assertEquals('RIT(AI)BC230826', $resAdminApps->json('data.0.batch_code'));

        // Step 15: Admin progresses status through all 7 stages
        $allStatuses = [
            'under_review',
            'shortlisted',
            'interview_scheduled',
            'selected',
            'rejected',
            'joined',
        ];

        foreach ($allStatuses as $st) {
            $payload = ['status' => $st];
            if ($st === 'interview_scheduled') {
                $payload['interview_date'] = Carbon::today()->addDays(5)->toDateTimeString();
                $payload['interview_notes'] = 'Technical round link: https://meet.google.com/abc-defg-hij';
            }
            $resUpdate = $this->putJson("/api/admin/placements/applications/{$appId}/status", $payload);
            $resUpdate->assertStatus(200);
            $this->assertEquals($st, PlacementApplication::find($appId)->status);
        }

        // Step 16: Student sees final updated status in My Applications
        Sanctum::actingAs($studentA);
        $resFinalStudentCheck = $this->getJson('/api/placements/my-applications');
        $resFinalStudentCheck->assertStatus(200);
        $this->assertEquals('joined', $resFinalStudentCheck->json('0.status'));

        // Step 17: Student cannot modify administrative status
        $resStudentTamper = $this->putJson("/api/admin/placements/applications/{$appId}/status", ['status' => 'selected']);
        $resStudentTamper->assertStatus(403);

        // Step 18: Student A must never be able to view Student B's applications
        Sanctum::actingAs($studentB);
        $resStudentBApps = $this->getJson('/api/placements/my-applications');
        $resStudentBApps->assertStatus(200);
        $this->assertCount(0, $resStudentBApps->json());
    }

    public function test_single_active_session_enforcement_on_placement_routes(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'current_session_id' => 'valid_session_now',
        ]);

        $revokedToken = $student->createToken('old_tab', ['session:revoked_session_id'])->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer ' . $revokedToken)
            ->getJson('/api/placements/my-applications');

        $res->assertStatus(401)
            ->assertJsonFragment(['code' => 'SESSION_REVOKED']);
    }
}
