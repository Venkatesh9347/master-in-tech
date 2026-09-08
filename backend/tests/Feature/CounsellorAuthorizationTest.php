<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CounsellorAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function counsellor(): User
    {
        return User::factory()->create(['role' => 'counsellor']);
    }

    public function test_counsellor_can_access_crm_lead_list(): void
    {
        $counsellor = $this->counsellor();
        Sanctum::actingAs($counsellor);

        $this->getJson('/api/admin/crm/leads')->assertOk();
    }

    public function test_counsellor_can_access_enquiries_list(): void
    {
        $counsellor = $this->counsellor();
        Sanctum::actingAs($counsellor);

        $this->getJson('/api/admin/enquiries')->assertOk();
    }

    public function test_counsellor_can_access_crm_batch_options(): void
    {
        $counsellor = $this->counsellor();
        Sanctum::actingAs($counsellor);

        $this->getJson('/api/admin/crm/batch-options')->assertOk();
    }

    public function test_counsellor_cannot_access_admin_dashboard(): void
    {
        $counsellor = $this->counsellor();
        Sanctum::actingAs($counsellor);

        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }

    public function test_counsellor_cannot_manage_users(): void
    {
        $counsellor = $this->counsellor();
        Sanctum::actingAs($counsellor);

        $this->getJson('/api/admin/users')->assertForbidden();
        $this->postJson('/api/admin/users', ['name' => 'Hack', 'email' => 'hack@example.com', 'role' => 'student'])->assertForbidden();
    }

    public function test_counsellor_cannot_create_course(): void
    {
        $counsellor = $this->counsellor();
        Sanctum::actingAs($counsellor);

        $this->postJson('/api/courses', [
            'title' => 'Counsellor Course',
            'description' => 'Should be blocked',
            'instructor' => 'Counsellor',
            'duration' => '1 week',
            'difficulty' => 'Beginner',
            'price' => 100,
        ])->assertForbidden();
    }

    public function test_counsellor_cannot_manage_batches(): void
    {
        $counsellor = $this->counsellor();
        Sanctum::actingAs($counsellor);

        $this->getJson('/api/admin/batches')->assertForbidden();
    }

    public function test_admin_can_still_access_all_crm_and_admin_areas(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/crm/leads')->assertOk();
        $this->getJson('/api/admin/enquiries')->assertOk();
        $this->getJson('/api/admin/dashboard')->assertOk();
        $this->getJson('/api/admin/users')->assertOk();
    }
}