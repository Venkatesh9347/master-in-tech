<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\PlacementOpportunity;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Services\PlacementSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlacementControlTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->create([
            'name' => 'Placement Director',
            'email' => 'director@masterintech.com',
            'role' => 'admin',
            'current_session_id' => 'admin_session_' . Str::random(10),
        ]);
    }

    private function createStudent(string $name = 'Sanjay Reddy'): array
    {
        $course = Course::create([
            'title' => 'Full Stack AI Engineering',
            'slug' => 'fullstack-ai-' . Str::random(5),
            'code' => 'AI',
            'description' => 'Comprehensive AI Course.',
            'category' => 'Engineering',
            'instructor' => 'Faculty Lead',
            'duration' => '16 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ]);

        $batch = Batch::create([
            'name' => 'AI Engineering Cohort',
            'code' => 'RIT(AI)BC230826',
            'course_id' => $course->id,
            'start_date' => '2026-09-01',
            'status' => 'active',
        ]);

        $student = User::factory()->create([
            'name' => $name,
            'email' => Str::slug($name) . '@student.com',
            'phone' => '+91 9876543210',
            'student_id' => 'STU-' . Str::upper(Str::random(5)),
            'role' => 'student',
            'current_session_id' => 'student_session_' . Str::random(10),
        ]);

        CourseEnrollment::create(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active', 'enrolled_at' => now()]);
        BatchStudent::create(['user_id' => $student->id, 'batch_id' => $batch->id, 'status' => 'active', 'joined_at' => now()]);

        return ['student' => $student, 'course' => $course, 'batch' => $batch];
    }

    private function createCompanyUser(): User
    {
        $company = Company::create([
            'name' => 'TechCorp Global',
            'email' => 'hr@techcorp.com',
            'phone' => '+91 9123456789',
            'industry' => 'Software',
            'hr_name' => 'Priya Sharma',
            'location' => 'Hyderabad',
            'status' => Company::STATUS_APPROVED,
        ]);

        return User::factory()->create([
            'name' => 'Priya Sharma',
            'email' => 'hr@techcorp.com',
            'role' => 'company',
            'company_id' => $company->id,
            'current_session_id' => 'company_session_' . Str::random(10),
        ]);
    }

    private function createPublishedJob(): PlacementOpportunity
    {
        return PlacementOpportunity::create([
            'title' => 'Software Development Engineer - 1',
            'company_name' => 'InnovateX Solutions',
            'location' => 'Hyderabad, India',
            'employment_type' => 'Full-time',
            'skills_required' => ['Java', 'Spring Boot', 'MySQL'],
            'description' => 'Core backend development opportunity.',
            'status' => PlacementOpportunity::STATUS_PUBLISHED,
        ]);
    }

    public function test_admin_can_read_and_update_placement_settings(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        // 1. Read default settings
        $resGet = $this->getJson('/api/admin/placement/settings');
        $resGet->assertStatus(200)
            ->assertJson([
                'placement_enabled' => true,
                'job_applications_enabled' => true,
            ]);

        // 2. Read via plural route alias
        $resGetPlural = $this->getJson('/api/admin/placements/settings');
        $resGetPlural->assertStatus(200)
            ->assertJsonFragment(['placement_enabled' => true]);

        // 3. Update placement settings
        $resPut = $this->putJson('/api/admin/placement/settings', [
            'placement_enabled' => false,
            'mock_interview_required' => true,
            'job_applications_enabled' => false,
        ]);

        $resPut->assertStatus(200)
            ->assertJson([
                'placement_enabled' => false,
                'mock_interview_required' => true,
                'job_applications_enabled' => false,
            ]);

        $this->assertFalse(PlacementSettingService::isPlacementEnabled());
        $this->assertFalse(PlacementSettingService::isJobApplicationsEnabled());
        $this->assertTrue(PlacementSettingService::isMockInterviewRequired());
    }

    public function test_non_admin_and_unauthenticated_cannot_update_settings(): void
    {
        $studentData = $this->createStudent('Ananya Sen');
        $student = $studentData['student'];
        $companyUser = $this->createCompanyUser();
        $tutor = User::factory()->create(['role' => 'tutor', 'current_session_id' => 'tutor_session_1']);

        // 1. Student attempts to update -> 403 Forbidden
        Sanctum::actingAs($student);
        $this->putJson('/api/admin/placement/settings', ['placement_enabled' => false])->assertStatus(403);
        $this->getJson('/api/admin/placement/settings')->assertStatus(403);

        // 2. Company attempts to update -> 403 Forbidden
        Sanctum::actingAs($companyUser);
        $this->putJson('/api/admin/placement/settings', ['placement_enabled' => false])->assertStatus(403);
        $this->getJson('/api/admin/placement/settings')->assertStatus(403);

        // 3. Tutor attempts to update -> 403 Forbidden
        Sanctum::actingAs($tutor);
        $this->putJson('/api/admin/placement/settings', ['placement_enabled' => false])->assertStatus(403);
        $this->getJson('/api/admin/placement/settings')->assertStatus(403);

        // 4. Unauthenticated guest -> 401 Unauthorized
        app('auth')->forgetGuards();
        $this->putJson('/api/admin/placement/settings', ['placement_enabled' => false])->assertStatus(401);
        $this->getJson('/api/admin/placement/settings')->assertStatus(401);
    }

    public function test_student_and_public_read_placement_availability_state(): void
    {
        // Default state: placement enabled
        $resPublic1 = $this->getJson('/api/placements/settings');
        $resPublic1->assertStatus(200)
            ->assertJson([
                'placement_enabled' => true,
                'placementEnabled' => true,
                'job_applications_enabled' => true,
            ]);

        // Admin disables placement
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);
        $this->putJson('/api/admin/placement/settings', ['placement_enabled' => false]);

        // Public / Student checks availability state -> receives disabled flag
        app('auth')->forgetGuards();
        $resPublic2 = $this->getJson('/api/placements/settings');
        $resPublic2->assertStatus(200)
            ->assertJson([
                'placement_enabled' => false,
                'placementEnabled' => false,
            ]);
    }

    public function test_when_placement_disabled_student_is_blocked_from_placement_portal(): void
    {
        $admin = $this->createAdmin();
        $studentData = $this->createStudent('Vikram Rao');
        $student = $studentData['student'];
        $job = $this->createPublishedJob();

        // 1. Admin disables placement
        Sanctum::actingAs($admin);
        $this->putJson('/api/admin/placement/settings', ['placement_enabled' => false]);

        // 2. Student attempts to access opportunities -> 403 PLACEMENT_DISABLED
        Sanctum::actingAs($student);
        $resOpp = $this->getJson('/api/placements/opportunities');
        $resOpp->assertStatus(403)
            ->assertJsonFragment(['code' => 'PLACEMENT_DISABLED']);

        // 3. Student attempts to view single opportunity -> 403 PLACEMENT_DISABLED
        $resSingle = $this->getJson("/api/placements/opportunities/{$job->id}");
        $resSingle->assertStatus(403)
            ->assertJsonFragment(['code' => 'PLACEMENT_DISABLED']);

        // 4. Student attempts profile prefill -> 403 PLACEMENT_DISABLED
        $resPrefill = $this->getJson('/api/placements/profile-prefill');
        $resPrefill->assertStatus(403)
            ->assertJsonFragment(['code' => 'PLACEMENT_DISABLED']);

        // 5. Student attempts to apply -> 403 PLACEMENT_DISABLED
        $resApply = $this->postJson("/api/placements/opportunities/{$job->id}/apply", [
            'batch_number' => 'RIT(AI)BC230826',
            'phone' => '+91 9876543210',
            'resume_url' => 'https://example.com/cv.pdf',
        ]);
        $resApply->assertStatus(403)
            ->assertJsonFragment(['code' => 'PLACEMENT_DISABLED']);

        // 6. Student attempts to view my-applications -> 403 PLACEMENT_DISABLED
        $resMyApps = $this->getJson('/api/placements/my-applications');
        $resMyApps->assertStatus(403)
            ->assertJsonFragment(['code' => 'PLACEMENT_DISABLED']);
    }

    public function test_existing_lms_access_remains_functional_when_placement_is_disabled(): void
    {
        $admin = $this->createAdmin();
        $studentData = $this->createStudent('Deepika Padukone');
        $student = $studentData['student'];

        // Admin disables placement
        Sanctum::actingAs($admin);
        $this->putJson('/api/admin/placement/settings', ['placement_enabled' => false]);

        // Student LMS features remain fully functional
        Sanctum::actingAs($student);
        $resCourses = $this->getJson('/api/courses');
        $resCourses->assertStatus(200);

        $resMyCourses = $this->getJson('/api/my-courses');
        $resMyCourses->assertStatus(200);

        $resClassSessions = $this->getJson('/api/student/class-sessions');
        $resClassSessions->assertStatus(200);
    }

    public function test_when_job_applications_disabled_opportunities_are_viewable_but_apply_is_blocked(): void
    {
        $admin = $this->createAdmin();
        $studentData = $this->createStudent('Karthik Varma');
        $student = $studentData['student'];
        $job = $this->createPublishedJob();

        // 1. Admin enables placement portal but pauses job applications
        Sanctum::actingAs($admin);
        $this->putJson('/api/admin/placement/settings', [
            'placement_enabled' => true,
            'job_applications_enabled' => false,
        ]);

        // 2. Student can view opportunities catalog
        Sanctum::actingAs($student);
        $resOpp = $this->getJson('/api/placements/opportunities');
        $resOpp->assertStatus(200);

        $resSingle = $this->getJson("/api/placements/opportunities/{$job->id}");
        $resSingle->assertStatus(200);

        // 3. Student attempting to apply is blocked with APPLICATIONS_PAUSED
        $resApply = $this->postJson("/api/placements/opportunities/{$job->id}/apply", [
            'batch_number' => 'RIT(AI)BC230826',
            'phone' => '+91 9876543210',
            'resume_url' => 'https://example.com/cv.pdf',
        ]);
        $resApply->assertStatus(403)
            ->assertJsonFragment(['code' => 'APPLICATIONS_PAUSED']);
    }

    public function test_mock_interview_required_configuration_state(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        // Admin enables mock interview requirement
        $this->putJson('/api/admin/placement/settings', [
            'placement_enabled' => true,
            'mock_interview_required' => true,
            'job_applications_enabled' => true,
        ]);

        $settings = PlacementSettingService::getSettings();
        $this->assertTrue($settings['placement_enabled']);
        $this->assertTrue($settings['mock_interview_required']);
        $this->assertTrue($settings['job_applications_enabled']);
    }

    public function test_cache_invalidation_upon_admin_update(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        // Populate cache
        PlacementSettingService::getSettings();
        $this->assertTrue(Cache::has(PlacementSettingService::CACHE_KEY));

        // Update settings -> cache is invalidated and fresh values returned
        $this->putJson('/api/admin/placement/settings', ['placement_enabled' => false]);
        $this->assertFalse(PlacementSettingService::isPlacementEnabled());
    }

    public function test_single_active_session_on_admin_placement_settings(): void
    {
        $admin = $this->createAdmin();

        // Old token with mismatched session id
        $revokedToken = $admin->createToken('old_tab', ['session:expired_session_999'])->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer ' . $revokedToken)
            ->getJson('/api/admin/placement/settings');

        $res->assertStatus(401)
            ->assertJsonFragment(['code' => 'SESSION_REVOKED']);
    }
}
