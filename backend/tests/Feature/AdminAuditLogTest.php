<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/admin/audit-logs')->assertStatus(401);
    }

    public function test_audit_log_endpoint_blocks_non_admin_roles(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        $this->getJson('/api/admin/audit-logs')->assertStatus(403);
    }

    public function test_audit_logs_filters_by_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Alice Auditor', 'email' => 'alice@test.com']);
        $otherAdmin = User::factory()->create(['role' => 'admin', 'name' => 'Bob Operations']);
        Sanctum::actingAs($admin);

        AuditLog::log('created_batch', new Batch(), null, []);
        Sanctum::actingAs($otherAdmin);
        AuditLog::log('created_course', new Course(), null, []);

        // Exact user_id filter
        Sanctum::actingAs($admin);
        $byId = $this->getJson('/api/admin/audit-logs?user_id=' . $admin->id)
            ->assertOk()
            ->json('data');
        $this->assertEquals($admin->id, $byId[0]['user_id']);

        // Name search
        $byName = $this->getJson('/api/admin/audit-logs?user=Alice')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $byName);
        $this->assertEquals('Alice Auditor', $byName[0]['user_name']);

        // Email search
        $emailPrefix = explode('@', $otherAdmin->email)[0];
        $byEmail = $this->getJson('/api/admin/audit-logs?user=' . $emailPrefix)
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $byEmail);
        $this->assertEquals('Bob Operations', $byEmail[0]['user_name']);
        $this->assertEquals($otherAdmin->email, $byEmail[0]['user']['email']);
    }

    public function test_audit_logs_filters_by_action_and_auditable_type(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $course = Course::create([
            'title' => 'Audit Target Course',
            'slug' => 'audit-target-course',
            'code' => 'ATC',
            'description' => 'D',
            'category' => 'Analytics',
            'instructor' => 'Senior',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ]);
        AuditLog::log('created_course', $course, null, []);
        AuditLog::log('updated_course', $course, ['x' => 1], ['x' => 2]);

        $byAction = $this->getJson('/api/admin/audit-logs?action=created_course')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $byAction);
        $this->assertEquals('created_course', $byAction[0]['action']);

        $byType = $this->getJson('/api/admin/audit-logs?auditable_type=' . urlencode(Course::class))
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $byType);
        $this->assertEquals(Course::class, $byType[0]['auditable_type']);
    }

    public function test_audit_logs_filters_by_date_range(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $oldLog = new AuditLog([
            'user_id' => $admin->id,
            'user_name' => $admin->name,
            'action' => 'old_action',
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
        ]);
        $oldLog->created_at = now()->subDays(10);
        $oldLog->updated_at = now()->subDays(10);
        $oldLog->save();
        AuditLog::log('new_action', null, null, null);

        $today = now()->toDateString();
        $oldDay = now()->subDays(10)->toDateString();

        $fromToday = $this->getJson("/api/admin/audit-logs?from={$today}")
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $fromToday);
        $this->assertEquals('new_action', $fromToday[0]['action']);

        $fullRange = $this->getJson("/api/admin/audit-logs?from={$oldDay}&to={$today}")
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $fullRange);
    }
}