<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Read-only admin certificate listing/detail backing the revocation
 * console. Safe projection only; revocation business rules are untouched
 * (covered by CertificateRevocationTest).
 */
class AdminCertificateListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function makeCourse(string $prefix = 'Cert list'): Course
    {
        $title = $prefix . ' ' . Str::random(6);

        return Course::create([
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(4),
            'description' => 'Certificate list fixture.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => 100.00,
        ]);
    }

    private function makeCertificate(array $overrides = []): Certificate
    {
        $student = $overrides['user'] ?? User::factory()->create(['role' => 'student']);
        $course = $overrides['course'] ?? $this->makeCourse();

        $defaults = [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'certificate_code' => 'MIT-2026-' . strtoupper(Str::random(12)),
            'issued_at' => now(),
            'status' => Certificate::STATUS_ACTIVE,
        ];

        unset($overrides['user'], $overrides['course']);

        return Certificate::create(array_merge($defaults, $overrides));
    }

    private function list(array $params = [])
    {
        $query = http_build_query($params);

        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/certificates' . ($query !== '' ? '?' . $query : ''));
    }

    public function test_admin_can_list_certificates_paginated(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->makeCertificate();
        }

        $this->list()->assertOk()
            ->assertJsonPath('per_page', 15)
            ->assertJsonPath('total', 20)
            ->assertJsonCount(15, 'data');
    }

    public function test_per_page_bounds(): void
    {
        $this->makeCertificate();

        $this->list(['per_page' => 5])->assertOk()->assertJsonCount(1, 'data');
        $this->list(['per_page' => 0])->assertOk()->assertJsonPath('per_page', 15);
        $this->list(['per_page' => 500])->assertOk()->assertJsonPath('per_page', 100);
    }

    public function test_ordering_is_id_descending(): void
    {
        $first = $this->makeCertificate();
        $second = $this->makeCertificate();

        $ids = $this->list()->assertOk()->json('data.*.id');

        $this->assertSame([$second->id, $first->id], $ids);
    }

    public function test_filters_status_search_and_course(): void
    {
        $active = $this->makeCertificate(['status' => Certificate::STATUS_ACTIVE]);
        $this->makeCertificate([
            'status' => Certificate::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_by' => $this->admin->id,
            'revocation_reason' => 'Fixture revocation reason here.',
        ]);

        $this->list(['status' => 'active'])->assertOk()->assertJsonPath('total', 1);
        $this->list(['status' => 'revoked'])->assertOk()->assertJsonPath('total', 1);
        $this->list(['status' => 'no-such-status'])->assertOk()->assertJsonPath('total', 0);

        $this->list(['search' => $active->certificate_code])->assertOk()->assertJsonPath('total', 1);
        $this->list(['search' => 'no-such-certificate'])->assertOk()->assertJsonPath('total', 0);

        $this->list(['course_id' => $active->course_id])->assertOk()->assertJsonPath('total', 1);
        $this->list(['course_id' => 999999])->assertOk()->assertJsonPath('total', 0);
    }

    public function test_search_matches_student_and_course(): void
    {
        $student = User::factory()->create(['role' => 'student', 'name' => 'Listable Learner']);
        $course = $this->makeCourse('Searchable Course Title');
        $this->makeCertificate(['user' => $student, 'course' => $course]);
        $this->makeCertificate();

        $this->list(['search' => 'Listable Learner'])->assertOk()->assertJsonPath('total', 1);
        $this->list(['search' => substr($student->email, 0, 12)])->assertOk()->assertJsonPath('total', 1);
        $this->list(['search' => 'Searchable Course Title'])->assertOk()->assertJsonPath('total', 1);
    }

    public function test_list_exposes_safe_projection_only(): void
    {
        $certificate = $this->makeCertificate(['pdf_path' => 'certificates/secret.pdf']);

        $item = $this->list()->assertOk()->json('data.0');

        $this->assertSame($certificate->id, $item['id']);
        $this->assertSame($certificate->certificate_code, $item['certificate_code']);
        $this->assertSame('active', $item['status']);
        $this->assertArrayHasKey('issued_at', $item);
        $this->assertArrayHasKey('revoked_at', $item);
        $this->assertArrayHasKey('revoked_by', $item);
        $this->assertArrayHasKey('revocation_reason', $item);
        $this->assertSame(['id', 'name', 'email'], array_keys($item['user']));
        $this->assertSame(['id', 'title'], array_keys($item['course']));

        $body = $this->list()->assertOk()->getContent();

        foreach (['pdf_path', 'secret', 'authorization', 'trace', 'Exception'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_authorization(): void
    {
        $this->makeCertificate();

        // Unauthenticated first: actingAs persists for later requests in
        // the same test.
        $this->getJson('/api/admin/certificates')->assertStatus(401);

        $this->actingAs(User::factory()->create(['role' => 'student']), 'sanctum')
            ->getJson('/api/admin/certificates')
            ->assertStatus(403);

        $this->actingAs(User::factory()->create(['role' => 'tutor']), 'sanctum')
            ->getJson('/api/admin/certificates')
            ->assertStatus(403);

        $this->actingAs(User::factory()->create(['role' => 'telecaller']), 'sanctum')
            ->getJson('/api/admin/certificates')
            ->assertStatus(403);

        $this->actingAs(User::factory()->create(['role' => 'counsellor']), 'sanctum')
            ->getJson('/api/admin/certificates')
            ->assertStatus(403);
    }

    public function test_show_returns_certificate_detail(): void
    {
        $certificate = $this->makeCertificate([
            'status' => Certificate::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_by' => $this->admin->id,
            'revocation_reason' => 'Fixture revocation reason here.',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/certificates/{$certificate->id}")
            ->assertOk();

        $payload = $response->json('certificate');

        $this->assertSame($certificate->id, $payload['id']);
        $this->assertSame($certificate->certificate_code, $payload['certificate_code']);
        $this->assertSame('revoked', $payload['status']);
        $this->assertSame($this->admin->id, $payload['revoked_by']);
        $this->assertSame('Fixture revocation reason here.', $payload['revocation_reason']);
        $this->assertSame($this->admin->id, $payload['revoked_by_user']['id']);

        $this->assertStringNotContainsString('pdf_path', (string) $response->getContent());
    }

    public function test_show_unknown_certificate_is_safe_404(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/certificates/999999');

        $response->assertStatus(404)->assertJson(['message' => 'Not found.']);
        $this->assertStringNotContainsString('App\\Models', (string) $response->getContent());
    }

    public function test_show_authorization(): void
    {
        $certificate = $this->makeCertificate();

        $this->getJson("/api/admin/certificates/{$certificate->id}")->assertStatus(401);

        $this->actingAs(User::factory()->create(['role' => 'student']), 'sanctum')
            ->getJson("/api/admin/certificates/{$certificate->id}")
            ->assertStatus(403);
    }
}
