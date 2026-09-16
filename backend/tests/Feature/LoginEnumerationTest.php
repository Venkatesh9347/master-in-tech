<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * NEW-SEC-03 regression: password-login failures must be externally
 * indistinguishable regardless of account existence or status.
 *
 * Enforcement is unchanged (blocked accounts still receive no token);
 * only the observable 422 response is normalized.
 */
class LoginEnumerationTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_ERRORS = ['email' => ['The provided credentials are incorrect.']];

    private function login(string $email, string $password): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function test_nonexistent_and_wrong_password_are_indistinguishable(): void
    {
        $existing = User::factory()->create([
            'email' => 'exists@example.com',
            'password' => Hash::make('correct-password-123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $missing = $this->login('nobody-here@example.com', 'whatever-123')->assertStatus(422);
        $wrongPassword = $this->login('exists@example.com', 'wrong-password-123')->assertStatus(422);

        $this->assertEquals(self::GENERIC_ERRORS, $missing->json('errors'));
        $this->assertEquals($missing->json(), $wrongPassword->json());
        $this->assertArrayNotHasKey('access_token', $missing->json());
    }

    public function test_blocked_account_statuses_match_generic_failure(): void
    {
        foreach (['disabled', 'inactive', 'suspended'] as $status) {
            $user = User::factory()->create([
                'email' => "{$status}@example.com",
                'password' => Hash::make('correct-password-123'),
                'role' => 'student',
                'status' => $status,
            ]);

            // Correct password on a blocked account: still blocked...
            $blocked = $this->login("{$status}@example.com", 'correct-password-123')->assertStatus(422);
            $this->assertEquals(self::GENERIC_ERRORS, $blocked->json('errors'));
            $this->assertArrayNotHasKey('access_token', $blocked->json());

            // ...and identical to a plain wrong-password response.
            $wrong = $this->login("{$status}@example.com", 'wrong-password-123')->assertStatus(422);
            $this->assertEquals($wrong->json(), $blocked->json());

            $this->assertTrue($user->fresh()->tokens->isEmpty());
        }
    }

    public function test_unapproved_company_matches_generic_failure(): void
    {
        $company = Company::create([
            'name' => 'Pending Corp',
            'email' => 'hr@pending-corp.com',
            'phone' => '+91 9000000003',
            'industry' => 'Testing',
            'company_size' => '1-10',
            'location' => 'Remote',
            'hr_name' => 'HR Person',
            'status' => Company::STATUS_PENDING,
        ]);
        User::factory()->create([
            'name' => 'HR Person',
            'email' => 'hr@pending-corp.com',
            'password' => Hash::make('correct-password-123'),
            'role' => 'company',
            'company_id' => $company->id,
            'status' => 'active',
        ]);

        $response = $this->login('hr@pending-corp.com', 'correct-password-123')->assertStatus(422);
        $this->assertEquals(self::GENERIC_ERRORS, $response->json('errors'));
        $this->assertArrayNotHasKey('access_token', $response->json());
    }

    public function test_valid_login_behavior_unchanged(): void
    {
        User::factory()->create([
            'email' => 'active@example.com',
            'password' => Hash::make('correct-password-123'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->login('active@example.com', 'correct-password-123')
            ->assertStatus(200)
            ->assertJsonStructure(['message', 'access_token', 'token_type', 'user'])
            ->assertJsonPath('token_type', 'Bearer');
    }

    public function test_login_route_remains_throttled(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => (string) $r->uri() === 'api/login'
                && in_array('POST', $r->methods(), true));

        $this->assertCount(1, $routes);

        foreach ($routes as $route) {
            $this->assertContains(
                'throttle:auth-login',
                $route->gatherMiddleware(),
                'POST api/login must retain the auth-login throttle.'
            );
        }
    }
}
