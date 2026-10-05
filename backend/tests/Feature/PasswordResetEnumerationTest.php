<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordResetEnumerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_response_is_identical_for_known_and_unknown_emails(): void
    {
        User::factory()->create([
            'email' => 'known.user@example.com',
        ]);

        $known = $this->postJson('/api/forgot-password', [
            'email' => 'known.user@example.com',
        ]);

        $unknown = $this->postJson('/api/forgot-password', [
            'email' => 'nobody.there@example.com',
        ]);

        $known->assertStatus(200);
        $unknown->assertStatus(200);

        // Indistinguishable responses: no account-existence oracle.
        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame(
            'Password reset link sent successfully.',
            $unknown->json('message')
        );
    }

    public function test_forgot_password_still_validates_email_format(): void
    {
        $this->postJson('/api/forgot-password', [
            'email' => 'not-an-email',
        ])->assertStatus(422);
    }
}
