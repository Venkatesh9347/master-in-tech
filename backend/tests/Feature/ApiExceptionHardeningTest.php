<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Centralized HTTP error hardening (bootstrap/app.php):
 *
 * 1. findOrFail/firstOrFail failures arrive as NotFoundHttpException
 *    carrying "No query results for model [App\Models\X]" and must be
 *    normalized to a generic 404.
 * 2. Unauthenticated api/* requests without a JSON Accept header must
 *    receive JSON 401 instead of a 500 from the missing `login` route.
 */
class ApiExceptionHardeningTest extends TestCase
{
    use RefreshDatabase;

    /* Finding 1: model-not-found normalization */

    public function test_api_nonexistent_resource_returns_safe_404(): void
    {
        $response = $this->getJson('/api/public/courses/does-not-exist-xyz');

        $response->assertNotFound();
        $response->assertJson(['message' => 'Not found.']);
        $this->assertStringNotContainsString('App\\Models', (string) $response->getContent());
    }

    public function test_authenticated_missing_resource_stays_404_not_401_or_500(): void
    {
        // Existence resolves before enrollment authorization on this
        // endpoint, so an unknown id must stay a safe 404.
        $student = User::factory()->create(['role' => 'student']);

        $response = $this->actingAs($student, 'sanctum')->getJson('/api/assignments/999999');

        $response->assertNotFound();
        $response->assertJson(['message' => 'Not found.']);
        $this->assertStringNotContainsString('App\\Models', (string) $response->getContent());
    }

    public function test_404_body_contains_no_internals(): void
    {
        $body = (string) $this->getJson('/api/public/courses/does-not-exist-xyz')->getContent();

        $this->assertStringNotContainsString('trace', strtolower($body));
        $this->assertStringNotContainsString('.php', $body);
        $this->assertStringNotContainsString('SELECT', $body);
        $this->assertStringNotContainsString('Exception', $body);
    }

    /* Finding 2: unauthenticated API access without JSON Accept */

    public function test_unauthenticated_api_without_accept_header_returns_401_json(): void
    {
        $response = $this->call('GET', '/api/user', [], [], [], ['HTTP_ACCEPT' => 'text/html']);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthenticated.']);
        $this->assertStringNotContainsString('Route [login]', (string) $response->getContent());
    }

    public function test_unauthenticated_api_with_accept_json_remains_401(): void
    {
        $this->getJson('/api/user')
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_web_root_behavior_unchanged(): void
    {
        $this->get('/')->assertOk();
    }
}
