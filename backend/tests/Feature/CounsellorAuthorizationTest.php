<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Enquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CounsellorAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_counsellor_can_access_crm_dashboard(): void
    {
        $counsellor = User::factory()->create(['role' => 'counsellor']);

        $response = $this->actingAs($counsellor, 'sanctum')
            ->getJson('/api/admin/crm/stats');

        $response->assertOk();
    }

    public function test_counsellor_can_list_batches_for_crm_conversion(): void
    {
        $counsellor = User::factory()->create(['role' => 'counsellor']);

        $response = $this->actingAs($counsellor, 'sanctum')
            ->getJson('/api/admin/crm/batches');

        $response->assertOk();
    }

    public function test_counsellor_can_access_enquiries_pipeline(): void
    {
        $counsellor = User::factory()->create(['role' => 'counsellor']);
        $enquiry = Enquiry::create([
            'name' => 'Test Lead',
            'email' => 'lead@example.com',
            'phone' => '9876543210',
            'course_name' => 'Full Stack Web Development',
            'status' => 'new',
        ]);

        $response = $this->actingAs($counsellor, 'sanctum')
            ->getJson('/api/admin/enquiries');

        $response->assertOk();

        $detail = $this->actingAs($counsellor, 'sanctum')
            ->getJson("/api/admin/enquiries/{$enquiry->id}");

        $detail->assertOk();
    }

    public function test_counsellor_cannot_access_admin_only_endpoints(): void
    {
        $counsellor = User::factory()->create(['role' => 'counsellor']);

        $response = $this->actingAs($counsellor, 'sanctum')
            ->getJson('/api/admin/users');

        $response->assertForbidden();
    }

    public function test_counsellor_cannot_create_courses(): void
    {
        $counsellor = User::factory()->create(['role' => 'counsellor']);

        $response = $this->actingAs($counsellor, 'sanctum')
            ->postJson('/api/courses', [
                'title' => 'Unsolicited Course',
                'description' => 'Should be forbidden',
                'instructor' => 'Counsellor',
                'price' => 999,
                'duration' => '1 week',
                'difficulty' => 'Beginner',
            ]);

        $response->assertForbidden();
    }

    public function test_counsellor_cannot_access_batch_management_mutations(): void
    {
        $counsellor = User::factory()->create(['role' => 'counsellor']);

        $response = $this->actingAs($counsellor, 'sanctum')
            ->getJson('/api/admin/batches');

        $response->assertForbidden();
    }

    public function test_admin_can_still_access_enquiries_and_batch_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/enquiries')->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/batches')->assertOk();
    }
}