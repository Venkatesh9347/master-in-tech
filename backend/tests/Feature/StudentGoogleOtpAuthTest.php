<?php

namespace Tests\Feature;

use App\Mail\StudentLoginOtpMail;
use App\Models\StudentLoginOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StudentGoogleOtpAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_google_login_generates_30_second_hashed_otp_without_returning_plaintext_or_sanctum_token(): void
    {
        // Pre-create approved student account (Goal 3A requirement)
        $student = User::factory()->create([
            'name' => 'Alex Sharma',
            'email' => 'student.alex@example.com',
            'student_id' => 'STU-1001',
            'role' => 'student',
            'status' => 'active',
            'phone' => '+919876543210',
        ]);

        $response = $this->postJson('/api/auth/google', [
            'credential' => 'test_mock_google_token_:student.alex@example.com:Alex Sharma:google_sub_123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'requires_otp',
                'temp_token',
                'email',
                'masked_email',
                'expires_in',
                'resend_cooldown',
            ])
            ->assertJson([
                'requires_otp' => true,
                'email' => 'student.alex@example.com',
                'expires_in' => 30,
                'resend_cooldown' => 30,
            ]);

        // Ensure raw OTP is NEVER returned in response
        $this->assertArrayNotHasKey('otp', $response->json());
        $this->assertArrayNotHasKey('access_token', $response->json());

        // Ensure user was updated with google_id
        $student->refresh();
        $this->assertEquals('google_sub_123', $student->google_id);

        // Ensure OTP record was created with hashed OTP and 30s expiry
        $tempToken = $response->json('temp_token');
        $otpRecord = StudentLoginOtp::where('temp_token_hash', hash('sha256', $tempToken))->first();
        $this->assertNotNull($otpRecord);
        $this->assertEquals(0, $otpRecord->attempts);
        $this->assertEquals(3, $otpRecord->max_attempts);
        $this->assertTrue($otpRecord->expires_at->isFuture());

        // Ensure transactional email was sent
        Mail::assertSent(StudentLoginOtpMail::class, function ($mail) {
            return $mail->hasTo('student.alex@example.com')
                && strlen($mail->otp) === 6
                && ctype_digit($mail->otp)
                && $mail->expirySeconds === 30;
        });
    }

    public function test_unregistered_google_account_is_rejected_without_auto_account_creation(): void
    {
        $response = $this->postJson('/api/auth/google', [
            'credential' => 'test_mock_google_token_:unregistered.visitor@example.com:Unregistered Visitor:google_sub_unreg',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['credential']);

        // Account must NOT have been created
        $this->assertDatabaseMissing('users', [
            'email' => 'unregistered.visitor@example.com',
        ]);
    }

    public function test_deactivated_google_account_matches_unregistered_response(): void
    {
        User::factory()->create([
            'name' => 'Deactivated Dana',
            'email' => 'deactivated.dana@example.com',
            'role' => 'student',
            'status' => 'disabled',
        ]);

        $deactivated = $this->postJson('/api/auth/google', [
            'credential' => 'test_mock_google_token_:deactivated.dana@example.com:Deactivated Dana:google_sub_deact',
        ]);

        $unregistered = $this->postJson('/api/auth/google', [
            'credential' => 'test_mock_google_token_:ghost.someone@example.com:Ghost Someone:google_sub_ghost',
        ]);

        $deactivated->assertStatus(422);
        $unregistered->assertStatus(422);

        // Indistinguishable account-state responses: no registration oracle.
        $this->assertSame(
            $unregistered->json('errors.credential.0'),
            $deactivated->json('errors.credential.0')
        );
    }

    public function test_successful_otp_verification_issues_sanctum_token_and_immediately_destroys_otp(): void
    {
        User::factory()->create([
            'name' => 'Verify Student',
            'email' => 'verify.student@example.com',
            'role' => 'student',
            'status' => 'active',
        ]);

        $googleRes = $this->postJson('/api/auth/google', [
            'credential' => 'test_mock_google_token_:verify.student@example.com:Verify Student:google_sub_verify_1',
        ]);
        $tempToken = $googleRes->json('temp_token');

        // Extract sent OTP from fake mail
        $sentOtp = null;
        Mail::assertSent(StudentLoginOtpMail::class, function ($mail) use (&$sentOtp) {
            $sentOtp = $mail->otp;
            return true;
        });
        $this->assertNotNull($sentOtp);

        // Verify OTP
        $verifyRes = $this->postJson('/api/auth/otp/verify', [
            'temp_token' => $tempToken,
            'otp' => $sentOtp,
        ]);

        $verifyRes->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'access_token',
                'token_type',
                'user' => ['id', 'email', 'name', 'role'],
            ])
            ->assertJson([
                'token_type' => 'Bearer',
                'user' => [
                    'email' => 'verify.student@example.com',
                    'role' => 'student',
                ],
            ]);

        // Single-use enforcement: OTP record must be deleted
        $this->assertDatabaseMissing('student_login_otps', [
            'temp_token_hash' => hash('sha256', $tempToken),
        ]);

        // Attempting to re-verify must fail
        $reVerifyRes = $this->postJson('/api/auth/otp/verify', [
            'temp_token' => $tempToken,
            'otp' => $sentOtp,
        ]);
        $reVerifyRes->assertStatus(422);
    }

    public function test_mobile_otp_login_returns_equivalent_generic_response_for_known_and_unregistered_phones(): void
    {
        $student = User::factory()->create([
            'name' => 'Mobile Student',
            'email' => 'mobile.student@example.com',
            'student_id' => 'STU-1002',
            'phone' => '+919876500000',
            'role' => 'student',
            'status' => 'active',
        ]);

        // 1. Approved student sends mobile OTP
        $res = $this->postJson('/api/auth/mobile/send-otp', [
            'phone' => '+91 98765 00000',
        ]);

        // HIGH-5: the response must not expose raw phone information.
        $res->assertStatus(200)
            ->assertJsonMissing(['phone'])
            ->assertJsonStructure([
                'message',
                'requires_otp',
                'temp_token',
                'masked_phone',
                'expires_in',
                'resend_cooldown',
            ])
            ->assertJson([
                'requires_otp' => true,
                'expires_in' => 30,
            ]);

        $tempToken = $res->json('temp_token');

        // Extract sent OTP
        $sentOtp = null;
        Mail::assertSent(StudentLoginOtpMail::class, function ($mail) use (&$sentOtp) {
            $sentOtp = $mail->otp;
            return true;
        });

        // 2. Verify mobile OTP
        $verifyRes = $this->postJson('/api/auth/mobile/verify-otp', [
            'temp_token' => $tempToken,
            'otp' => $sentOtp,
        ]);

        $verifyRes->assertStatus(200)
            ->assertJson([
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $student->id,
                    'email' => 'mobile.student@example.com',
                ],
            ]);

        // 3. An unregistered mobile number receives an equivalent generic
        //    response so the endpoint cannot be used to enumerate accounts.
        $unknownPhone = '+919999999999';
        $unregRes = $this->postJson('/api/auth/mobile/send-otp', [
            'phone' => $unknownPhone,
        ]);

        $unregRes->assertStatus(200)
            ->assertJsonMissing(['phone'])
            ->assertJson([
                'requires_otp' => true,
                'expires_in' => 30,
                'masked_phone' => '+91 ******9999',
            ]);

        // The unknown-response temp token opens a fake pre-auth session:
        // verify/resend must answer exactly like a registered number's
        // session, otherwise the generic dispatch would re-enumerate
        // accounts one step later.
        $unknownToken = $unregRes->json('temp_token');
        $this->assertNotEquals($tempToken, $unknownToken);

        $unverifiedRes = $this->postJson('/api/auth/mobile/verify-otp', [
            'temp_token' => $unknownToken,
            'otp' => '123456',
        ]);
        $unverifiedRes->assertStatus(422)
            ->assertJsonValidationErrors(['otp'])
            ->assertJsonFragment(['Incorrect verification code. 2 attempt(s) remaining.']);
    }

    public function test_public_registration_endpoint_is_disabled(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Public User',
            'email' => 'public@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Public registration is disabled. Student accounts are created by MasterInTech administration following counselling. Please submit an enquiry to get started.',
            ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'public@example.com',
        ]);
    }

    public function test_otp_verification_fails_after_30_seconds_expiry(): void
    {
        User::factory()->create([
            'name' => 'Expired Student',
            'email' => 'expired.student@example.com',
            'role' => 'student',
            'status' => 'active',
        ]);

        $googleRes = $this->postJson('/api/auth/google', [
            'credential' => 'test_mock_google_token_:expired.student@example.com:Expired Student:google_sub_exp',
        ]);
        $tempToken = $googleRes->json('temp_token');

        $sentOtp = null;
        Mail::assertSent(StudentLoginOtpMail::class, function ($mail) use (&$sentOtp) {
            $sentOtp = $mail->otp;
            return true;
        });

        // Fast-forward time past 30 seconds
        $this->travel(31)->seconds();

        $verifyRes = $this->postJson('/api/auth/otp/verify', [
            'temp_token' => $tempToken,
            'otp' => $sentOtp,
        ]);

        $verifyRes->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        // Must be invalidated
        $this->assertDatabaseMissing('student_login_otps', [
            'temp_token_hash' => hash('sha256', $tempToken),
        ]);
    }

    public function test_incorrect_otp_increments_attempts_and_locks_after_max_attempts(): void
    {
        User::factory()->create([
            'name' => 'Attempts Student',
            'email' => 'attempts.student@example.com',
            'role' => 'student',
            'status' => 'active',
        ]);

        $googleRes = $this->postJson('/api/auth/google', [
            'credential' => 'test_mock_google_token_:attempts.student@example.com:Attempts Student:google_sub_att',
        ]);
        $tempToken = $googleRes->json('temp_token');

        // Attempt 1: wrong code
        $res1 = $this->postJson('/api/auth/otp/verify', [
            'temp_token' => $tempToken,
            'otp' => '000000',
        ]);
        $res1->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $record = StudentLoginOtp::where('temp_token_hash', hash('sha256', $tempToken))->first();
        $this->assertEquals(1, $record->attempts);

        // Attempt 2: wrong code
        $res2 = $this->postJson('/api/auth/otp/verify', [
            'temp_token' => $tempToken,
            'otp' => '111111',
        ]);
        $res2->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $record->refresh();
        $this->assertEquals(2, $record->attempts);

        // Attempt 3: wrong code -> locks and deletes
        $res3 = $this->postJson('/api/auth/otp/verify', [
            'temp_token' => $tempToken,
            'otp' => '222222',
        ]);
        $res3->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->assertDatabaseMissing('student_login_otps', [
            'temp_token_hash' => hash('sha256', $tempToken),
        ]);
    }

    public function test_resend_otp_enforces_30_second_cooldown(): void
    {
        User::factory()->create([
            'name' => 'Cooldown Student',
            'email' => 'cooldown.student@example.com',
            'role' => 'student',
            'status' => 'active',
        ]);

        $googleRes = $this->postJson('/api/auth/google', [
            'credential' => 'test_mock_google_token_:cooldown.student@example.com:Cooldown Student:google_sub_cd',
        ]);
        $tempToken = $googleRes->json('temp_token');

        // Immediate resend attempt must fail due to cooldown
        $resendFail = $this->postJson('/api/auth/otp/resend', [
            'temp_token' => $tempToken,
        ]);
        $resendFail->assertStatus(422)
            ->assertJsonValidationErrors(['resend']);

        // Travel 30 seconds forward
        $this->travel(30)->seconds();

        // Resend must now succeed and issue a new temp token and fresh OTP
        $resendOk = $this->postJson('/api/auth/otp/resend', [
            'temp_token' => $tempToken,
        ]);
        $resendOk->assertStatus(200)
            ->assertJsonStructure(['temp_token', 'expires_in', 'resend_cooldown'])
            ->assertJson([
                'expires_in' => 30,
                'resend_cooldown' => 30,
            ]);

        $newTempToken = $resendOk->json('temp_token');

        // Old temp token must no longer exist
        $this->assertDatabaseMissing('student_login_otps', [
            'temp_token_hash' => hash('sha256', $tempToken),
        ]);

        // New temp token must exist
        $this->assertDatabaseHas('student_login_otps', [
            'temp_token_hash' => hash('sha256', $newTempToken),
        ]);
    }

    public function test_google_login_accepts_code_parameter_and_issues_otp(): void
    {
        User::factory()->create([
            'name' => 'Code Student',
            'email' => 'code.student@example.com',
            'role' => 'student',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/auth/google', [
            'code' => 'test_mock_google_token_:code.student@example.com:Code Student:google_sub_code_1',
            'redirect_uri' => 'http://localhost:5173/login',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'requires_otp' => true,
                'email' => 'code.student@example.com',
                'expires_in' => 30,
                'resend_cooldown' => 30,
            ]);
    }

    public function test_google_login_rejects_empty_payload(): void
    {
        $response = $this->postJson('/api/auth/google', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['credential']);
    }

    public function test_client_cannot_override_email_identity_during_google_auth(): void
    {
        User::factory()->create([
            'name' => 'Genuine Student',
            'email' => 'genuine.student@example.com',
            'role' => 'student',
            'status' => 'active',
        ]);

        // Client attempts to pass a spoofed 'email' in request body
        $response = $this->postJson('/api/auth/google', [
            'credential' => 'test_mock_google_token_:genuine.student@example.com:Genuine Student:google_sub_genuine',
            'email' => 'victim.target@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'requires_otp' => true,
                'email' => 'genuine.student@example.com',
            ]);

        // OTP email must strictly be sent to genuine Google email, NEVER spoofed email
        Mail::assertSent(StudentLoginOtpMail::class, function ($mail) {
            return $mail->hasTo('genuine.student@example.com') && ! $mail->hasTo('victim.target@example.com');
        });
    }

    public function test_otp_hash_is_secure_and_raw_otp_is_never_stored_in_plaintext(): void
    {
        User::factory()->create([
            'name' => 'Secure Student',
            'email' => 'secure.student@example.com',
            'role' => 'student',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/auth/google', [
            'credential' => 'test_mock_google_token_:secure.student@example.com:Secure Student:google_sub_sec',
        ]);

        $tempToken = $response->json('temp_token');
        $otpRecord = StudentLoginOtp::where('temp_token_hash', hash('sha256', $tempToken))->first();

        $this->assertNotNull($otpRecord);
        // OTP hash must be a bcrypt hash (starts with $2y$)
        $this->assertStringStartsWith('$2y$', $otpRecord->otp_hash);
        // temp_token in DB must be sha256 hash, not raw temp_token
        $this->assertNotEquals($tempToken, $otpRecord->temp_token_hash);
    }

    public function test_otp_verification_is_rate_limited_per_source_ip(): void
    {
        // HIGH-5: verification attempts must be capped per source IP so an
        // attacker cannot rotate fresh OTP sessions to bypass the guess limit.
        // (In the testing environment the limiter allows a burst of 60/min.)
        $attempts = 61;
        $lastStatus = null;

        for ($i = 0; $i < $attempts; $i++) {
            $res = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
                ->postJson('/api/auth/otp/verify', [
                    'temp_token' => 'invalid_token_' . $i,
                    'otp' => '000000',
                ]);
            $lastStatus = $res->getStatusCode();
        }

        $this->assertEquals(429, $lastStatus, 'OTP verification must be throttled after exceeding the per-IP limit.');
    }

    public function test_unknown_number_verify_matches_registered_number_failure_sequence(): void
    {
        // HIGH-5: a fake pre-auth session must walk the identical failure
        // sequence as a real session (remaining attempts, then lockout),
        // otherwise verify() re-enumerates registered numbers.
        User::factory()->create([
            'name' => 'Real Student',
            'email' => 'real.student@example.com',
            'role' => 'student',
            'status' => 'active',
            'phone' => '+919876543211',
        ]);

        $realRes = $this->postJson('/api/auth/mobile/send-otp', ['phone' => '+919876543211']);
        $realToken = $realRes->json('temp_token');

        $fakeRes = $this->postJson('/api/auth/mobile/send-otp', ['phone' => '+910000000000']);
        $fakeToken = $fakeRes->json('temp_token');

        foreach (['111111', '222222'] as $i => $wrongOtp) {
            $expectedRemaining = 2 - $i;

            $realFail = $this->postJson('/api/auth/mobile/verify-otp', [
                'temp_token' => $realToken,
                'otp' => $wrongOtp,
            ]);
            $fakeFail = $this->postJson('/api/auth/mobile/verify-otp', [
                'temp_token' => $fakeToken,
                'otp' => $wrongOtp,
            ]);

            $realFail->assertStatus(422);
            $fakeFail->assertStatus(422);
            $this->assertEquals(
                $realFail->json('errors.otp.0'),
                $fakeFail->json('errors.otp.0'),
                'Fake-session verify response must equal the real-session response.'
            );
            $this->assertStringContainsString("{$expectedRemaining} attempt(s) remaining", (string) $fakeFail->json('errors.otp.0'));
        }

        // Third wrong guess locks both sessions identically.
        $realLock = $this->postJson('/api/auth/mobile/verify-otp', [
            'temp_token' => $realToken,
            'otp' => '333333',
        ]);
        $fakeLock = $this->postJson('/api/auth/mobile/verify-otp', [
            'temp_token' => $fakeToken,
            'otp' => '333333',
        ]);

        $realLock->assertStatus(422);
        $fakeLock->assertStatus(422);
        $this->assertEquals($realLock->json('errors.otp.0'), $fakeLock->json('errors.otp.0'));
        $this->assertStringContainsString('Maximum verification attempts exceeded', (string) $fakeLock->json('errors.otp.0'));

        // No OTP row and no user were ever created for the unknown number.
        $this->assertDatabaseMissing('student_login_otps', [
            'temp_token_hash' => hash('sha256', $fakeToken),
        ]);
        $this->assertDatabaseMissing('users', ['phone' => '+910000000000']);
    }

    public function test_unknown_number_resend_matches_registered_number_cooldown_and_rotation(): void
    {
        User::factory()->create([
            'name' => 'Resend Student',
            'email' => 'resend.student@example.com',
            'role' => 'student',
            'status' => 'active',
            'phone' => '+919876543212',
        ]);

        $realRes = $this->postJson('/api/auth/mobile/send-otp', ['phone' => '+919876543212']);
        $realToken = $realRes->json('temp_token');

        $fakeRes = $this->postJson('/api/auth/mobile/send-otp', ['phone' => '+910000000001']);
        $fakeToken = $fakeRes->json('temp_token');

        // Immediate resend hits the cooldown on both sessions alike.
        $realCool = $this->postJson('/api/auth/otp/resend', ['temp_token' => $realToken]);
        $fakeCool = $this->postJson('/api/auth/otp/resend', ['temp_token' => $fakeToken]);

        $realCool->assertStatus(422);
        $fakeCool->assertStatus(422);
        $this->assertArrayHasKey('resend', (array) $realCool->json('errors'));
        $this->assertArrayHasKey('resend', (array) $fakeCool->json('errors'));

        // After the cooldown both sessions rotate to a fresh token.
        $this->travel(31)->seconds();

        $realRot = $this->postJson('/api/auth/otp/resend', ['temp_token' => $realToken]);
        $fakeRot = $this->postJson('/api/auth/otp/resend', ['temp_token' => $fakeToken]);

        $realRot->assertStatus(200);
        $fakeRot->assertStatus(200);
        $this->assertNotEquals($fakeToken, $fakeRot->json('temp_token'));
        $fakeRot->assertJsonStructure(['temp_token', 'expires_in', 'resend_cooldown']);

        // The rotated fake token verifies with the same wrong-code message.
        $fakeVerify = $this->postJson('/api/auth/mobile/verify-otp', [
            'temp_token' => $fakeRot->json('temp_token'),
            'otp' => '444444',
        ]);
        $fakeVerify->assertStatus(422)
            ->assertJsonFragment(['Incorrect verification code. 2 attempt(s) remaining.']);
    }

    public function test_unknown_number_session_expires_like_registered_session(): void
    {
        $fakeRes = $this->postJson('/api/auth/mobile/send-otp', ['phone' => '+910000000002']);
        $fakeToken = $fakeRes->json('temp_token');

        $this->travel(31)->seconds();

        $fakeVerify = $this->postJson('/api/auth/mobile/verify-otp', [
            'temp_token' => $fakeToken,
            'otp' => '555555',
        ]);
        $fakeVerify->assertStatus(422)
            ->assertJsonFragment(['Verification code has expired (30-second limit). Please click Resend Code.']);
    }
}
