<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P1-B: server-driven pagination for admin list endpoints.
 *
 * Verifies Laravel paginator shape, default/requested pages, bounded
 * per_page, page isolation, filters applied before pagination,
 * deterministic ordering, and preserved authorization.
 */
class PaginationTest extends TestCase
{
    use RefreshDatabase;

    private function createCourse(array $attributes = []): Course
    {
        $title = $attributes['title'] ?? 'Course ' . Str::random(5);

        return Course::create(array_merge([
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => 'Pagination test course.',
            'category' => 'Engineering',
            'instructor' => 'Senior Specialist',
            'duration' => '8 weeks',
            'difficulty' => 'Intermediate',
            'is_published' => true,
        ], $attributes));
    }

    private function assertPaginatorShape(array $json, array $requiredKeys = []): void
    {
        foreach (['data', 'current_page', 'last_page', 'per_page', 'total'] as $key) {
            $this->assertArrayHasKey($key, $json, "Paginator response must include '{$key}'.");
        }
        $this->assertIsArray($json['data']);
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }
    }

    public function test_users_list_returns_paginated_envelope_by_default(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(20)->create(['role' => 'student']);

        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/admin/users');
        $res->assertStatus(200);

        $json = $res->json();
        $this->assertPaginatorShape($json);
        $this->assertSame(1, $json['current_page']);
        $this->assertSame(15, $json['per_page']);
        $this->assertCount(15, $json['data']);
        $this->assertSame(21, $json['total']);
        $this->assertSame(2, $json['last_page']);
    }

    public function test_users_pages_are_isolated_and_deterministic(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(20)->create(['role' => 'student']);

        Sanctum::actingAs($admin);

        $page1 = $this->getJson('/api/admin/users?per_page=5&page=1')->assertStatus(200)->json();
        $page1Repeat = $this->getJson('/api/admin/users?per_page=5&page=1')->assertStatus(200)->json();
        $page2 = $this->getJson('/api/admin/users?per_page=5&page=2')->assertStatus(200)->json();

        // Deterministic ordering: identical requests return identical rows.
        $this->assertSame(
            array_column($page1['data'], 'id'),
            array_column($page1Repeat['data'], 'id')
        );

        // Page isolation: no row appears on both pages.
        $this->assertCount(5, $page1['data']);
        $this->assertCount(5, $page2['data']);
        $this->assertEmpty(array_intersect(
            array_column($page1['data'], 'id'),
            array_column($page2['data'], 'id')
        ));
        $this->assertSame($page1['total'], $page2['total']);
    }

    public function test_users_per_page_is_bounded_and_validated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(3)->create(['role' => 'student']);

        Sanctum::actingAs($admin);

        // Excessive per_page is capped, not honored.
        $capped = $this->getJson('/api/admin/users?per_page=500')->assertStatus(200)->json();
        $this->assertSame(100, $capped['per_page']);

        // Zero / non-numeric per_page falls back to the default.
        $zero = $this->getJson('/api/admin/users?per_page=0')->assertStatus(200)->json();
        $this->assertSame(15, $zero['per_page']);

        $invalid = $this->getJson('/api/admin/users?per_page=abc')->assertStatus(200)->json();
        $this->assertSame(15, $invalid['per_page']);
    }

    public function test_users_filters_apply_before_pagination(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'student', 'name' => 'Filter Target Alpha']);
        User::factory()->count(5)->create(['role' => 'student']);
        User::factory()->count(2)->create(['role' => 'tutor']);

        Sanctum::actingAs($admin);

        $search = $this->getJson('/api/admin/users?search=Filter%20Target%20Alpha')->assertStatus(200)->json();
        $this->assertSame(1, $search['total']);
        $this->assertCount(1, $search['data']);

        $role = $this->getJson('/api/admin/users?role=tutor')->assertStatus(200)->json();
        $this->assertSame(2, $role['total']);
        $this->assertCount(2, $role['data']);
    }

    public function test_users_list_authorization_preserved(): void
    {
        $this->getJson('/api/admin/users')->assertStatus(401);

        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);
        $this->getJson('/api/admin/users')->assertStatus(403);
    }

    public function test_enrollments_list_is_paginated_with_filters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->createCourse();

        foreach (range(1, 4) as $i) {
            $student = User::factory()->create(['role' => 'student']);
            CourseEnrollment::create([
                'user_id' => $student->id,
                'course_id' => $course->id,
                'status' => $i <= 2 ? 'active' : 'completed',
                'enrolled_at' => now(),
            ]);
        }

        Sanctum::actingAs($admin);

        $all = $this->getJson('/api/admin/enrollments?per_page=2')->assertStatus(200)->json();
        $this->assertPaginatorShape($all);
        $this->assertSame(4, $all['total']);
        $this->assertSame(2, $all['last_page']);
        $this->assertCount(2, $all['data']);

        $filtered = $this->getJson('/api/admin/enrollments?status=completed')->assertStatus(200)->json();
        $this->assertSame(2, $filtered['total']);
        $this->assertCount(2, $filtered['data']);
        foreach ($filtered['data'] as $row) {
            $this->assertSame('completed', $row['status']);
        }
    }

    public function test_batches_list_and_history_are_paginated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->createCourse();

        foreach (range(1, 4) as $i) {
            Batch::create([
                'name' => "Cohort {$i}",
                'code' => 'PAG' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'course_id' => $course->id,
                'start_date' => now()->addDays($i)->toDateString(),
                'status' => 'upcoming',
            ]);
        }

        Sanctum::actingAs($admin);

        $list = $this->getJson('/api/admin/batches?per_page=3')->assertStatus(200)->json();
        $this->assertPaginatorShape($list);
        $this->assertSame(4, $list['total']);
        $this->assertCount(3, $list['data']);

        $history = $this->getJson('/api/admin/batches/history')->assertStatus(200)->json();
        $this->assertPaginatorShape($history);
    }

    public function test_enquiries_list_is_paginated_with_filters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = $this->createCourse();

        foreach (['Anna One', 'Anna Two', 'Bob Three'] as $index => $name) {
            Enquiry::create([
                'name' => $name,
                'email' => "enquiry{$index}@example.com",
                'phone' => '+91 900000000' . $index,
                'course_id' => $course->id,
                'status' => Enquiry::STATUS_NEW,
            ]);
        }

        Sanctum::actingAs($admin);

        $search = $this->getJson('/api/admin/enquiries?search=Anna')->assertStatus(200)->json();
        $this->assertPaginatorShape($search);
        $this->assertSame(2, $search['total']);
        $this->assertCount(2, $search['data']);

        $page = $this->getJson('/api/admin/enquiries?per_page=2&page=2')->assertStatus(200)->json();
        $this->assertSame(3, $page['total']);
        $this->assertCount(1, $page['data']);
        $this->assertSame(2, $page['current_page']);
    }
}
