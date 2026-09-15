<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S-01: API exception sanitization regression coverage.
 *
 * API error responses must never expose stack traces, absolute filesystem
 * paths, exception class names, or source file/line details — regardless of
 * the APP_DEBUG flag. Intentional HTTP semantics (401/403/404/422/429) and
 * their safe response shapes must be preserved.
 */
class ApiExceptionSanitizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_nonexistent_api_route_does_not_disclose_implementation_details(): void
    {
        $response = $this->getJson('/api/nonexistent-xyz');

        $response->assertNotFound()
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('line')
            ->assertJsonMissingPath('trace');

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('Exception', $content);
        $this->assertStringNotContainsString('AbstractRouteCollection', $content);
        // No absolute filesystem paths (Windows or POSIX) may leak.
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z]:\\\\/', $content);
        $this->assertStringNotContainsString('/vendor/', $content);
    }

    public function test_unhandled_server_exception_returns_generic_500_without_details(): void
    {
        Route::prefix('api')->middleware('api')->get('/_s1-boom', function () {
            throw new \RuntimeException('db connection exploded on secret-host');
        });

        $response = $this->getJson('/api/_s1-boom');

        $response->assertStatus(500)
            ->assertExactJson(['message' => 'Server Error']);

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('secret-host', $content);
        $this->assertStringNotContainsString('RuntimeException', $content);
        $this->assertStringNotContainsString('trace', $content);
    }

    public function test_debug_mode_does_not_reenable_api_trace_disclosure(): void
    {
        config(['app.debug' => true]);

        Route::prefix('api')->middleware('api')->get('/_s1-boom-debug', function () {
            throw new \RuntimeException('boom');
        });

        $this->getJson('/api/nonexistent-xyz')
            ->assertNotFound()
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('line')
            ->assertJsonMissingPath('trace');

        $this->getJson('/api/_s1-boom-debug')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Server Error']);
    }

    public function test_unauthenticated_protected_endpoint_still_returns_401(): void
    {
        $response = $this->getJson('/api/admin/dashboard');

        $response->assertUnauthorized()
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
    }

    public function test_forbidden_role_still_returns_403(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertForbidden()
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
    }

    public function test_missing_resource_still_returns_404_with_safe_message(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        // Explicit controller 404 contract is preserved verbatim.
        $this->getJson('/api/courses/99999999')
            ->assertNotFound()
            ->assertJson(['message' => 'Course not found'])
            ->assertJsonMissingPath('trace');

        // Model-binding 404s must not leak the model class name.
        $response = $this->getJson('/api/admin/users/99999999');
        if ($response->status() === 404) {
            $response->assertJsonMissingPath('trace');
            $this->assertStringNotContainsString(
                'App\\Models',
                (string) $response->getContent()
            );
        } else {
            // Non-admin callers are rejected by role middleware first.
            $response->assertForbidden();
        }
    }

    public function test_validation_failures_still_return_422_with_errors(): void
    {
        $response = $this->postJson('/api/login', []);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors'])
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
    }

    public function test_throttled_responses_keep_429_semantics_without_details(): void
    {
        Route::prefix('api')->middleware(['api', 'throttle:2,1'])->get('/_s1-throttle', function () {
            return response()->json(['ok' => true]);
        });

        $this->getJson('/api/_s1-throttle')->assertOk();
        $this->getJson('/api/_s1-throttle')->assertOk();

        $response = $this->getJson('/api/_s1-throttle');

        $response->assertStatus(429)
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
        $this->assertTrue($response->headers->has('Retry-After'));
    }
}
