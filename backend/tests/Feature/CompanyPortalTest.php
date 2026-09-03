<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\PlacementApplication;
use App\Models\PlacementInterview;
use App\Models\PlacementOpportunity;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\PlacementSettingService::updateSettings(['mock_interview_required' => false]);
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'name' => 'Placement Director',
            'email' => 'director@masterintech.com',
            'role' => 'admin',
        ]);
    }

    private function createCompany(array $attributes = []): Company
    {
        return Company::create(array_merge([
            'name' => 'CloudTech Systems Inc',
            'email' => 'recruiter@cloudtech.com',
            'phone' => '+91 9876543210',
            'industry' => 'Cloud Computing & AI',
            'company_size' => '51-200',
            'location' => 'Hyderabad, India',
            'hr_name' => 'Sarah Jenkins',
            'status' => Company::STATUS_APPROVED,
        ], $attributes));
    }

    private function createCompanyUser(Company $company, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'name' => $company->hr_name,
            'email' => $company->email,
            'role' => 'company',
            'company_id' => $company->id,
            'status' => 'active',
            'current_session_id' => 'valid_session_' . Str::random(10),
        ], $attributes));

        $company->update(['created_user_id' => $user->id]);
        return $user;
    }

    private function createStudent(string $name = 'Rahul Varma', string $batchCode = 'RIT(AI)BC230826'): array
    {
        $course = Course::create([
            'title' => 'Master In AI & Cloud',
            'slug' => 'master-ai-cloud-' . Str::random(5),
            'code' => 'AI',
            'description' => 'Course description.',
            'category' => 'Engineering',
            'instructor' => 'Lead Architect',
            'duration' => '12 weeks',
            'difficulty' => 'Advanced',
            'is_published' => true,
        ]);

        $batch = Batch::create([
            'name' => 'AI Cohort',
            'code' => $batchCode,
            'course_id' => $course->id,
            'start_date' => '2026-09-01',
            'status' => 'active',
        ]);

        $student = User::factory()->create([
            'name' => $name,
            'email' => Str::slug($name) . '@student.com',
            'phone' => '+91 9123456789',
            'student_id' => 'STU-' . Str::upper(Str::random(5)),
            'role' => 'student',
            'current_session_id' => 'student_session_' . Str::random(10),
        ]);

        CourseEnrollment::create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active', 'enrolled_at' => now()]);
        BatchStudent::create(['user_id' => $student->id, 'batch_id' => $batch->id, 'status' => 'active', 'joined_at' => now()]);

        return ['student' => $student, 'course' => $course, 'batch' => $batch];
    }

    public function test_public_corporate_partner_registration(): void
    {
        $payload = [
            'name' => 'InnovateX AI Labs',
            'website' => 'https://innovatex.ai',
            'industry' => 'Artificial Intelligence',
            'company_size' => '201-1000',
            'hr_name' => 'Vikram Seth',
            'email' => 'careers@innovatex.ai',
            'phone' => '+91 9988776655',
            'location' => 'Bangalore / Hybrid',
            'hiring_technologies' => ['PyTorch', 'FastAPI', 'AWS Bedrock', 'Docker'],
            'hiring_requirements' => 'Looking for 10 Full Stack AI Engineers from current batches.',
            'message' => 'Interested in campus placement partnership for 2026 batch.',
        ];

        $res = $this->postJson('/api/corporate-partner/register', $payload);
        $res->assertStatus(201);
        $res->assertJsonFragment(['status' => 'pending']);

        $this->assertDatabaseHas('companies', [
            'name' => 'InnovateX AI Labs',
            'email' => 'careers@innovatex.ai',
            'status' => 'pending',
        ]);
    }

    public function test_admin_approves_partner_and_provisions_recruiter_account(): void
    {
        $admin = $this->createAdmin();
        $company = $this->createCompany(['status' => Company::STATUS_PENDING, 'email' => 'partner@nexus.io']);

        Sanctum::actingAs($admin);

        $res = $this->postJson("/api/admin/placements/partners/{$company->id}/approve", [
            'password' => 'NexusPartner#2026',
        ]);

        $res->assertStatus(200);
        $this->assertEquals(Company::STATUS_APPROVED, $company->fresh()->status);

        $this->assertDatabaseHas('users', [
            'email' => 'partner@nexus.io',
            'role' => 'company',
            'company_id' => $company->id,
            'status' => 'active',
        ]);
    }

    public function test_unapproved_or_suspended_company_cannot_login_or_access_portal(): void
    {
        $pendingCompany = $this->createCompany(['status' => Company::STATUS_PENDING, 'email' => 'pending@corp.com']);
        $pendingUser = $this->createCompanyUser($pendingCompany);

        // 1. Attempt login with pending company -> blocked
        $resLogin = $this->postJson('/api/login', [
            'email' => 'pending@corp.com',
            'password' => 'secret',
        ]);
        $resLogin->assertStatus(422);

        // 2. Direct API call with pending token -> 403 Forbidden
        Sanctum::actingAs($pendingUser);
        $resDash = $this->getJson('/api/company/dashboard');
        $resDash->assertStatus(403);
    }

    public function test_approved_company_can_access_dashboard_and_profile(): void
    {
        $company = $this->createCompany(['name' => 'Apex Cloud Inc']);
        $user = $this->createCompanyUser($company);

        Sanctum::actingAs($user);

        $resDash = $this->getJson('/api/company/dashboard');
        $resDash->assertStatus(200)
            ->assertJsonStructure(['company', 'active_jobs', 'total_applications', 'upcoming_interviews']);

        $resProf = $this->getJson('/api/company/profile');
        $resProf->assertStatus(200)
            ->assertJsonFragment(['name' => 'Apex Cloud Inc']);

        // Update profile
        $resUpdate = $this->putJson('/api/company/profile', [
            'description' => 'Global leader in enterprise multi-cloud solutions.',
            'company_size' => '1000+',
        ]);
        $resUpdate->assertStatus(200);
        $this->assertEquals('1000+', $company->fresh()->company_size);
    }

    public function test_company_job_posting_approval_and_placement_portal_workflow(): void
    {
        $admin = $this->createAdmin();
        $company = $this->createCompany(['name' => 'Cognitive AI Labs']);
        $companyUser = $this->createCompanyUser($company);

        // 1. Company creates job posting (starts in pending_approval)
        Sanctum::actingAs($companyUser);
        $resJob = $this->postJson('/api/company/jobs', [
            'title' => 'MLOps Platform Engineer',
            'employment_type' => 'Full-time',
            'work_mode' => 'Hybrid',
            'location' => 'Hyderabad / Bangalore',
            'salary_package' => '12.0 - 16.0 LPA',
            'experience_required' => '0 - 2 Years',
            'eligibility' => 'B.Tech / MCA / MasterInTech Graduates',
            'skills_required' => ['Python', 'Kubernetes', 'MLflow', 'Docker'],
            'description' => 'Design robust CI/CD pipelines for large language model deployment.',
            'openings_count' => 4,
            'deadline_date' => Carbon::today()->addDays(30)->toDateString(),
            'status' => 'pending_approval',
        ]);

        $resJob->assertStatus(201);
        $jobId = $resJob->json('job.id');
        $this->assertEquals('pending_approval', PlacementOpportunity::find($jobId)->status);

        // 2. Unpublished company job does NOT appear in public Placement Portal
        $resPublicBefore = $this->getJson('/api/placements/opportunities');
        $resPublicBefore->assertStatus(200);
        $publicJobTitles = collect($resPublicBefore->json('data'))->pluck('title')->toArray();
        $this->assertNotContains('MLOps Platform Engineer', $publicJobTitles);

        // 3. Admin reviews and approves the pending company job
        Sanctum::actingAs($admin);
        $resPendingList = $this->getJson('/api/admin/placements/jobs/pending');
        $resPendingList->assertStatus(200);
        $this->assertCount(1, $resPendingList->json('data'));

        $resApprove = $this->postJson("/api/admin/placements/jobs/{$jobId}/approve");
        $resApprove->assertStatus(200);
        $this->assertEquals('published', PlacementOpportunity::find($jobId)->status);

        // 4. Approved job now appears in public Placement Portal
        $resPublicAfter = $this->getJson('/api/placements/opportunities');
        $resPublicAfter->assertStatus(200);
        $publicJobTitlesAfter = collect($resPublicAfter->json('data'))->pluck('title')->toArray();
        $this->assertContains('MLOps Platform Engineer', $publicJobTitlesAfter);
    }

    public function test_company_multi_tenant_job_isolation(): void
    {
        $companyA = $this->createCompany(['name' => 'Company A', 'email' => 'a@company.com']);
        $companyB = $this->createCompany(['name' => 'Company B', 'email' => 'b@company.com']);

        $userA = $this->createCompanyUser($companyA);
        $userB = $this->createCompanyUser($companyB);

        $jobA = PlacementOpportunity::create([
            'company_id' => $companyA->id,
            'company_name' => 'Company A',
            'title' => 'Software Engineer at A',
            'location' => 'Remote',
            'employment_type' => 'Full-time',
            'skills_required' => ['Golang'],
            'description' => 'Job description A',
            'status' => 'published',
        ]);

        $jobB = PlacementOpportunity::create([
            'company_id' => $companyB->id,
            'company_name' => 'Company B',
            'title' => 'Software Engineer at B',
            'location' => 'Remote',
            'employment_type' => 'Full-time',
            'skills_required' => ['Rust'],
            'description' => 'Job description B',
            'status' => 'published',
        ]);

        // User A views their jobs list -> only sees Job A
        Sanctum::actingAs($userA);
        $resA = $this->getJson('/api/company/jobs');
        $resA->assertStatus(200);
        $titlesA = collect($resA->json('data'))->pluck('title')->toArray();
        $this->assertContains('Software Engineer at A', $titlesA);
        $this->assertNotContains('Software Engineer at B', $titlesA);

        // User A attempts to edit Job B -> 403 Forbidden
        $resEdit = $this->putJson("/api/company/jobs/{$jobB->id}", ['title' => 'Hacked Job Title']);
        $resEdit->assertStatus(403);
        $this->assertEquals('Software Engineer at B', $jobB->fresh()->title);

        // User A attempts to delete Job B -> 403 Forbidden
        $resDelete = $this->deleteJson("/api/company/jobs/{$jobB->id}");
        $resDelete->assertStatus(403);
        $this->assertDatabaseHas('placement_opportunities', ['id' => $jobB->id]);
    }

    public function test_company_applications_interviews_and_selection_lifecycle(): void
    {
        $company = $this->createCompany(['name' => 'Intellect Systems']);
        $companyUser = $this->createCompanyUser($company);

        $studentData = $this->createStudent('Aditya Rao', 'RIT(AI)BC230826');
        $student = $studentData['student'];
        $course = $studentData['course'];
        $batch = $studentData['batch'];

        // 1. Published Job for Company
        $job = PlacementOpportunity::create([
            'company_id' => $company->id,
            'company_name' => $company->name,
            'title' => 'Full Stack AI Engineer',
            'location' => 'Hyderabad',
            'employment_type' => 'Full-time',
            'skills_required' => ['React', 'Python', 'FastAPI'],
            'description' => 'Full stack development role.',
            'status' => 'published',
        ]);

        // 2. Student applies to job via Placement Portal
        Sanctum::actingAs($student);
        $resApply = $this->postJson("/api/placements/opportunities/{$job->id}/apply", [
            'batch_number' => 'RIT(AI)BC230826',
            'phone' => '+91 9123456789',
            'resume_url' => 'https://storage.example.com/aditya_cv.pdf',
            'cover_note' => 'Graduated from MasterInTech with honors.',
        ]);
        $resApply->assertStatus(201);
        $appId = $resApply->json('application.id');

        // 3. Company sees application
        Sanctum::actingAs($companyUser);
        $resApps = $this->getJson('/api/company/applications');
        $resApps->assertStatus(200);
        $this->assertCount(1, $resApps->json('data'));
        $this->assertEquals('Aditya Rao', $resApps->json('data.0.student_name'));

        // 4. Company shortlists candidate
        $resShortlist = $this->putJson("/api/company/applications/{$appId}/status", [
            'status' => 'shortlisted',
        ]);
        $resShortlist->assertStatus(200);
        $this->assertEquals('shortlisted', PlacementApplication::find($appId)->status);

        // 5. Company schedules interview
        $interviewTime = Carbon::today()->addDays(3)->setTime(11, 0);
        $resSchedule = $this->postJson('/api/company/interviews', [
            'placement_application_id' => $appId,
            'interview_date' => $interviewTime->toISOString(),
            'interview_type' => 'online',
            'meeting_link' => 'https://meet.google.com/xyz-uvw-rst',
            'instructions' => 'Round 1: DSA and System Design coding round.',
        ]);
        $resSchedule->assertStatus(201);
        $interviewId = $resSchedule->json('interview.id');

        // 6. Student sees interview in Placement Portal -> My Applications
        Sanctum::actingAs($student);
        $resStudentApps = $this->getJson('/api/placements/my-applications');
        $resStudentApps->assertStatus(200);
        $this->assertEquals('interview_scheduled', $resStudentApps->json('0.status'));
        $this->assertStringContainsString('https://meet.google.com/xyz-uvw-rst', $resStudentApps->json('0.interview_notes'));

        // 7. Company submits interview feedback scorecard
        Sanctum::actingAs($companyUser);
        $resFeedback = $this->postJson("/api/company/interviews/{$interviewId}/feedback", [
            'technical_score' => 9,
            'communication_score' => 9,
            'overall_score' => 9,
            'feedback' => 'Exceptional system architecture knowledge and strong problem-solving skills.',
            'recommendation' => 'select',
        ]);
        $resFeedback->assertStatus(200);
        $this->assertEquals('completed', PlacementInterview::find($interviewId)->status);
        $this->assertEquals('selected', PlacementApplication::find($appId)->status);

        // 8. Student sees selection status in My Applications
        Sanctum::actingAs($student);
        $resFinalStudentCheck = $this->getJson('/api/placements/my-applications');
        $resFinalStudentCheck->assertStatus(200);
        $this->assertEquals('selected', $resFinalStudentCheck->json('0.status'));
    }

    public function test_role_guards_and_cross_portal_security(): void
    {
        $company = $this->createCompany();
        $companyUser = $this->createCompanyUser($company);

        $studentData = $this->createStudent('Sneha Patil');
        $student = $studentData['student'];

        // 1. Student attempts to access Company Dashboard -> 403 Forbidden
        Sanctum::actingAs($student);
        $this->getJson('/api/company/dashboard')->assertStatus(403);
        $this->getJson('/api/company/jobs')->assertStatus(403);
        $this->getJson('/api/company/applications')->assertStatus(403);

        // 2. Company user attempts to access Admin endpoints -> 403 Forbidden
        Sanctum::actingAs($companyUser);
        $this->getJson('/api/admin/placements/stats')->assertStatus(403);
        $this->getJson('/api/admin/placements/partners')->assertStatus(403);

        // 3. Unauthenticated guest -> 401 Unauthorized
        app('auth')->forgetGuards();
        $this->getJson('/api/company/dashboard')->assertStatus(401);
    }

    public function test_cross_company_application_access_prevention(): void
    {
        $companyA = $this->createCompany(['name' => 'Alpha Tech', 'email' => 'alpha@tech.com']);
        $companyB = $this->createCompany(['name' => 'Beta Systems', 'email' => 'beta@systems.com']);

        $userA = $this->createCompanyUser($companyA);
        $userB = $this->createCompanyUser($companyB);

        $studentData = $this->createStudent('Kavita Rao');
        $student = $studentData['student'];

        $jobB = PlacementOpportunity::create([
            'company_id' => $companyB->id,
            'company_name' => 'Beta Systems',
            'title' => 'Backend Engineer at Beta',
            'location' => 'Hyderabad',
            'employment_type' => 'Full-time',
            'skills_required' => ['Node.js'],
            'description' => 'Role at Beta',
            'status' => 'published',
        ]);

        $appB = PlacementApplication::create([
            'placement_opportunity_id' => $jobB->id,
            'user_id' => $student->id,
            'batch_id' => $studentData['batch']->id,
            'batch_code' => $studentData['batch']->code,
            'student_name' => 'Kavita Rao',
            'email' => $student->email,
            'phone' => '+91 9988776655',
            'resume_url' => 'https://example.com/kavita.pdf',
            'status' => 'applied',
            'applied_at' => now(),
        ]);

        // User A views applications list -> does NOT see App B
        Sanctum::actingAs($userA);
        $resAppsA = $this->getJson('/api/company/applications');
        $resAppsA->assertStatus(200);
        $this->assertCount(0, $resAppsA->json('data'));

        // User A attempts to update status on App B -> 403 Forbidden
        $resTamper = $this->putJson("/api/company/applications/{$appB->id}/status", [
            'status' => 'shortlisted',
        ]);
        $resTamper->assertStatus(403);
        $this->assertEquals('applied', $appB->fresh()->status);
    }

    public function test_admin_rejection_suspension_and_reactivation_lifecycle(): void
    {
        $admin = $this->createAdmin();
        $company = $this->createCompany(['status' => Company::STATUS_PENDING, 'email' => 'lifecycle@partner.com']);

        Sanctum::actingAs($admin);

        // 1. Admin rejects company request
        $resReject = $this->postJson("/api/admin/placements/partners/{$company->id}/reject", [
            'reason' => 'Currently not hiring in our technology stack.',
        ]);
        $resReject->assertStatus(200);
        $this->assertEquals(Company::STATUS_REJECTED, $company->fresh()->status);

        // 2. Admin approves company
        $resApprove = $this->postJson("/api/admin/placements/partners/{$company->id}/approve");
        $resApprove->assertStatus(200);
        $this->assertEquals(Company::STATUS_APPROVED, $company->fresh()->status);

        // 3. Admin suspends company
        $resSuspend = $this->postJson("/api/admin/placements/partners/{$company->id}/suspend");
        $resSuspend->assertStatus(200);
        $this->assertEquals(Company::STATUS_SUSPENDED, $company->fresh()->status);

        // 4. Suspended company user is blocked from portal
        $user = User::where('email', 'lifecycle@partner.com')->first();
        Sanctum::actingAs($user);
        $this->getJson('/api/company/dashboard')->assertStatus(403);

        // 5. Admin reactivates company
        Sanctum::actingAs($admin);
        $resReactivate = $this->postJson("/api/admin/placements/partners/{$company->id}/reactivate");
        $resReactivate->assertStatus(200);
        $this->assertEquals(Company::STATUS_APPROVED, $company->fresh()->status);

        // 6. Reactivated company can now access dashboard
        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/company/dashboard')->assertStatus(200);
    }

    public function test_single_active_session_on_company_portal(): void
    {
        $company = $this->createCompany();
        $companyUser = $this->createCompanyUser($company);
        // Generate old token from previous device tab with mismatched session id
        $revokedToken = $companyUser->createToken('old_tab', ['session:expired_session_999'])->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer ' . $revokedToken)
            ->getJson('/api/company/dashboard');

        $res->assertStatus(401)
            ->assertJsonFragment(['code' => 'SESSION_REVOKED']);
    }

    public function test_company_cannot_access_student_dashboard_apis(): void
    {
        $company = $this->createCompany();
        $companyUser = $this->createCompanyUser($company);

        Sanctum::actingAs($companyUser);

        // Company attempts to hit student enrollment and class session endpoints -> 403
        $this->getJson('/api/my-courses')->assertStatus(403);
        $this->getJson('/api/student/class-sessions')->assertStatus(403);
    }

    public function test_complete_eighteen_step_end_to_end_partner_to_placement_lifecycle(): void
    {
        // 1. Admin creates / approves corporate partner
        $admin = $this->createAdmin();
        $company = $this->createCompany(['status' => Company::STATUS_PENDING, 'email' => 'techleads@futureai.io']);

        Sanctum::actingAs($admin);
        $resApprovePartner = $this->postJson("/api/admin/placements/partners/{$company->id}/approve", [
            'password' => 'FutureAI#2026Secure',
        ]);
        $resApprovePartner->assertStatus(200);

        // 2. Approved company can authenticate (login with password)
        app('auth')->forgetGuards();
        $resLogin = $this->postJson('/api/login', [
            'email' => 'techleads@futureai.io',
            'password' => 'FutureAI#2026Secure',
        ]);
        $resLogin->assertStatus(200);
        $companyToken = $resLogin->json('access_token');
        $this->assertNotEmpty($companyToken);

        // 3. Company can access Company Portal
        $resDash = $this->withHeader('Authorization', 'Bearer ' . $companyToken)
            ->getJson('/api/company/dashboard');
        $resDash->assertStatus(200)
            ->assertJsonFragment(['name' => $company->name]);

        // 4. Company profile loads correctly and updates
        $resProf = $this->withHeader('Authorization', 'Bearer ' . $companyToken)
            ->getJson('/api/company/profile');
        $resProf->assertStatus(200);

        $resUpdateProf = $this->withHeader('Authorization', 'Bearer ' . $companyToken)
            ->putJson('/api/company/profile', [
                'description' => 'Autonomous AI agents infrastructure company.',
                'company_size' => '201-1000',
            ]);
        $resUpdateProf->assertStatus(200);

        // 5. Company creates a job
        $resCreateJob = $this->withHeader('Authorization', 'Bearer ' . $companyToken)
            ->postJson('/api/company/jobs', [
                'title' => 'Senior Agentic AI Engineer',
                'employment_type' => 'Full-time',
                'work_mode' => 'Hybrid',
                'location' => 'Hyderabad, India',
                'salary_package' => '18.0 - 24.0 LPA',
                'experience_required' => '0 - 2 Years',
                'eligibility' => 'MasterInTech AI Cohort Graduates',
                'skills_required' => ['Python', 'FastAPI', 'LangChain', 'Docker'],
                'description' => 'Build generative AI pipelines and autonomous workflow agents.',
                'status' => 'pending_approval',
            ]);
        $resCreateJob->assertStatus(201);
        $jobId = $resCreateJob->json('job.id');

        // 6. Job remains pending until Admin approval (not visible in public placement catalog)
        $resPublicCatalog1 = $this->getJson('/api/placements/opportunities');
        $resPublicCatalog1->assertStatus(200);
        $this->assertNotContains('Senior Agentic AI Engineer', collect($resPublicCatalog1->json('data'))->pluck('title')->toArray());

        // 7. Admin approves the job
        Sanctum::actingAs($admin);
        $resApproveJob = $this->postJson("/api/admin/placements/jobs/{$jobId}/approve");
        $resApproveJob->assertStatus(200);

        // 8. Approved job appears in the existing Placement Portal
        $resPublicCatalog2 = $this->getJson('/api/placements/opportunities');
        $resPublicCatalog2->assertStatus(200);
        $this->assertContains('Senior Agentic AI Engineer', collect($resPublicCatalog2->json('data'))->pluck('title')->toArray());

        // 9. Student can view the job
        $studentData = $this->createStudent('Manish Rao', 'RIT(AI)BC230826');
        $student = $studentData['student'];

        Sanctum::actingAs($student);
        $resJobDetails = $this->getJson("/api/placements/opportunities/{$jobId}");
        $resJobDetails->assertStatus(200)
            ->assertJsonFragment(['title' => 'Senior Agentic AI Engineer']);

        // 10. Student can apply using the existing placement application workflow
        $resApply = $this->postJson("/api/placements/opportunities/{$jobId}/apply", [
            'batch_number' => 'RIT(AI)BC230826',
            'phone' => '+91 9876543210',
            'resume_url' => 'https://storage.masterintech.com/resumes/manish_ai_cv.pdf',
            'cover_note' => 'Certified AI Specialist from MasterInTech.',
        ]);
        $resApply->assertStatus(201);
        $appId = $resApply->json('application.id');

        // 11. Company can see the application
        app('auth')->forgetGuards();
        $resCompanyApps = $this->withHeader('Authorization', 'Bearer ' . $companyToken)
            ->getJson('/api/company/applications');
        $resCompanyApps->assertStatus(200);
        $this->assertCount(1, $resCompanyApps->json('data'));
        $this->assertEquals('Manish Rao', $resCompanyApps->json('data.0.student_name'));

        // 12. Company can shortlist the student
        $resShortlist = $this->withHeader('Authorization', 'Bearer ' . $companyToken)
            ->putJson("/api/company/applications/{$appId}/status", [
                'status' => 'shortlisted',
            ]);
        $resShortlist->assertStatus(200);
        $this->assertEquals('shortlisted', PlacementApplication::find($appId)->status);

        // 13. Company can schedule an interview
        $interviewDate = Carbon::today()->addDays(2)->setTime(14, 30);
        $resSchedule = $this->withHeader('Authorization', 'Bearer ' . $companyToken)
            ->postJson('/api/company/interviews', [
                'placement_application_id' => $appId,
                'interview_date' => $interviewDate->toISOString(),
                'interview_type' => 'online',
                'meeting_link' => 'https://meet.google.com/futureai-round1',
                'instructions' => 'Live coding and agent architecture round.',
            ]);
        $resSchedule->assertStatus(201);
        $interviewId = $resSchedule->json('interview.id');

        // 14. Student can see the interview in My Applications
        Sanctum::actingAs($student);
        $resStudentApps1 = $this->getJson('/api/placements/my-applications');
        $resStudentApps1->assertStatus(200);
        $this->assertEquals('interview_scheduled', $resStudentApps1->json('0.status'));
        $this->assertStringContainsString('https://meet.google.com/futureai-round1', $resStudentApps1->json('0.interview_notes'));

        // 15. Company can submit interview feedback scorecard
        app('auth')->forgetGuards();
        $resFeedback = $this->withHeader('Authorization', 'Bearer ' . $companyToken)
            ->postJson("/api/company/interviews/{$interviewId}/feedback", [
                'technical_score' => 10,
                'communication_score' => 9,
                'overall_score' => 10,
                'feedback' => 'Exceptional proficiency in LLM architecture and autonomous agents.',
                'recommendation' => 'select',
            ]);
        $resFeedback->assertStatus(200);

        // 16. Company marks the candidate selected (automatically updated via recommendation = select)
        $this->assertEquals('selected', PlacementApplication::find($appId)->status);

        // 17. Student can see the updated application / selection status
        Sanctum::actingAs($student);
        $resStudentApps2 = $this->getJson('/api/placements/my-applications');
        $resStudentApps2->assertStatus(200);
        $this->assertEquals('selected', $resStudentApps2->json('0.status'));

        // 18. Offer/joining workflow: Admin can update application status to 'joined'
        Sanctum::actingAs($admin);
        $resAdminJoin = $this->putJson("/api/admin/placements/applications/{$appId}/status", [
            'status' => 'joined',
            'admin_notes' => 'Candidate accepted offer of 20 LPA and joined on 2026-10-01.',
        ]);
        $resAdminJoin->assertStatus(200);
        $this->assertEquals('joined', PlacementApplication::find($appId)->status);
    }
}
