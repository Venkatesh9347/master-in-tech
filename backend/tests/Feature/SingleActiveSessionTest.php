<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SingleActiveSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $studentUser;
    private User $adminUser;
    private User $tutorUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the clock so OTP timestamps are deterministic and a slow machine
        // (or shared test DB) can never let the 30-second window elapse mid-test.
        Carbon::setTestNow(Carbon::now());

        $this->studentUser = User::factory()->create([
            'email' => 'student.session@example.com',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
            'phone' => '+919876543210',
        ]);

        $this->adminUser = User::factory()->create([
            'email' => 'admin.session@example.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->tutorUser = User::factory()->create([
            'email' => 'tutor.session@example.com',
            'password' => Hash::make('password123'),
            'role' => 'tutor',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function resetSanctumGuard(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_device_a_login_works_then_device_b_login_revokes_device_a(): void
    {
        // 1. Device A logs in
        $loginResA = $this->postJson('/api/login', [
            'email' => 'student.session@example.com',
            'password' => 'password123',
        ]);
        $loginResA->assertStatus(200);
        $tokenA = $loginResA->json('access_token');

        // Verify Device A can access protected /api/user
        $userResA = $this->withToken($tokenA)->getJson('/api/user');
        $userResA->assertStatus(200)
            ->assertJsonPath('email', 'student.session@example.com');

        $this->resetSanctumGuard();

        // 2. Device B logs in
        $loginResB = $this->postJson('/api/login', [
            'email' => 'student.session@example.com',
            'password' => 'password123',
        ]);
        $loginResB->assertStatus(200);
        $tokenB = $loginResB->json('access_token');

        $this->resetSanctumGuard();

        // Verify Device B works
        $userResB = $this->withToken($tokenB)->getJson('/api/user');
        $userResB->assertStatus(200);

        $this->resetSanctumGuard();

        // 3. Device A makes an authenticated request -> must receive 401 with SESSION_REVOKED
        $revokedResA = $this->withToken($tokenA)->getJson('/api/user');
        $revokedResA->assertStatus(401)
            ->assertJson([
                'code' => 'SESSION_REVOKED',
                'message' => 'Your session has expired because your account was signed in on another device.',
            ]);

        $this->resetSanctumGuard();

        // 4. Device B still works
        $userResB2 = $this->withToken($tokenB)->getJson('/api/user');
        $userResB2->assertStatus(200);
    }

    public function test_logging_in_again_on_same_device_revokes_previous_session(): void
    {
        // Device B logs in first time
        $login1 = $this->postJson('/api/login', [
            'email' => 'student.session@example.com',
            'password' => 'password123',
        ]);
        $token1 = $login1->json('access_token');

        $this->resetSanctumGuard();

        // Device B logs in second time
        $login2 = $this->postJson('/api/login', [
            'email' => 'student.session@example.com',
            'password' => 'password123',
        ]);
        $token2 = $login2->json('access_token');

        $this->resetSanctumGuard();

        // First token is revoked
        $this->withToken($token1)->getJson('/api/user')
            ->assertStatus(401)
            ->assertJsonPath('code', 'SESSION_REVOKED');

        $this->resetSanctumGuard();

        // Second token is valid
        $this->withToken($token2)->getJson('/api/user')
            ->assertStatus(200);
    }

    public function test_google_and_gmail_otp_login_enforces_single_session(): void
    {
        // 1. Initial email/password login session
        $initialLogin = $this->postJson('/api/login', [
            'email' => 'student.session@example.com',
            'password' => 'password123',
        ]);
        $initialToken = $initialLogin->json('access_token');

        // Verify initial session works
        $this->withToken($initialToken)->getJson('/api/user')->assertStatus(200);

        $this->resetSanctumGuard();

        // 2. Google Login Initiation
        $mockGoogleToken = 'test_mock_google_token_:student.session@example.com:Student Session:mock_google_id_123';
        $googleRes = $this->postJson('/api/auth/google', [
            'credential' => $mockGoogleToken,
        ]);
        $googleRes->assertStatus(200)
            ->assertJsonPath('requires_otp', true);

        $tempToken = $googleRes->json('temp_token');

        // 3. Extract the generated OTP from DB and complete verification
        $otpRecord = \App\Models\StudentLoginOtp::where('user_id', $this->studentUser->id)->first();
        $this->assertNotNull($otpRecord);

        // Bypass email check in test by resetting hash with known 6-digit OTP
        $otpRecord->update(['otp_hash' => Hash::make('123456')]);

        $verifyRes = $this->postJson('/api/auth/otp/verify', [
            'temp_token' => $tempToken,
            'otp' => '123456',
        ]);
        $verifyRes->assertStatus(200);
        $newOtpToken = $verifyRes->json('access_token');

        $this->resetSanctumGuard();

        // New Google OTP token works
        $this->withToken($newOtpToken)->getJson('/api/user')->assertStatus(200);

        $this->resetSanctumGuard();

        // Previous token is revoked with SESSION_REVOKED
        $this->withToken($initialToken)->getJson('/api/user')
            ->assertStatus(401)
            ->assertJsonPath('code', 'SESSION_REVOKED');
    }

    public function test_mobile_otp_login_enforces_single_session(): void
    {
        // 1. Device A login
        $deviceALogin = $this->postJson('/api/login', [
            'email' => 'student.session@example.com',
            'password' => 'password123',
        ]);
        $tokenA = $deviceALogin->json('access_token');

        $this->resetSanctumGuard();

        // 2. Mobile Login Initiation
        $mobileRes = $this->postJson('/api/auth/mobile/send-otp', [
            'phone' => '+919876543210',
        ]);
        $mobileRes->assertStatus(200);
        $tempToken = $mobileRes->json('temp_token');

        // Complete OTP verification
        $otpRecord = \App\Models\StudentLoginOtp::where('user_id', $this->studentUser->id)->first();
        $otpRecord->update(['otp_hash' => Hash::make('654321')]);

        $verifyRes = $this->postJson('/api/auth/otp/verify', [
            'temp_token' => $tempToken,
            'otp' => '654321',
        ]);
        $verifyRes->assertStatus(200);
        $tokenB = $verifyRes->json('access_token');

        $this->resetSanctumGuard();

        // Token B works
        $this->withToken($tokenB)->getJson('/api/user')->assertStatus(200);

        $this->resetSanctumGuard();

        // Token A is revoked
        $this->withToken($tokenA)->getJson('/api/user')
            ->assertStatus(401)
            ->assertJsonPath('code', 'SESSION_REVOKED');
    }

    public function test_admin_and_tutor_sessions_follow_single_session_enforcement(): void
    {
        // Admin Device 1
        $adminLogin1 = $this->postJson('/api/login', [
            'email' => 'admin.session@example.com',
            'password' => 'password123',
        ]);
        $adminToken1 = $adminLogin1->json('access_token');

        $this->withToken($adminToken1)->getJson('/api/admin/dashboard')->assertStatus(200);

        $this->resetSanctumGuard();

        // Admin Device 2
        $adminLogin2 = $this->postJson('/api/login', [
            'email' => 'admin.session@example.com',
            'password' => 'password123',
        ]);
        $adminToken2 = $adminLogin2->json('access_token');

        $this->resetSanctumGuard();

        // Admin Device 2 succeeds
        $this->withToken($adminToken2)->getJson('/api/admin/dashboard')->assertStatus(200);

        $this->resetSanctumGuard();

        // Admin Device 1 revoked
        $this->withToken($adminToken1)->getJson('/api/admin/dashboard')
            ->assertStatus(401)
            ->assertJsonPath('code', 'SESSION_REVOKED');

        $this->resetSanctumGuard();

        // Tutor Device 1 & 2
        $tutorLogin1 = $this->postJson('/api/login', [
            'email' => 'tutor.session@example.com',
            'password' => 'password123',
        ]);
        $tutorToken1 = $tutorLogin1->json('access_token');

        $this->withToken($tutorToken1)->getJson('/api/tutor/stats')->assertStatus(200);

        $this->resetSanctumGuard();

        $tutorLogin2 = $this->postJson('/api/login', [
            'email' => 'tutor.session@example.com',
            'password' => 'password123',
        ]);
        $tutorToken2 = $tutorLogin2->json('access_token');

        $this->resetSanctumGuard();

        $this->withToken($tutorToken2)->getJson('/api/tutor/stats')->assertStatus(200);

        $this->resetSanctumGuard();

        $this->withToken($tutorToken1)->getJson('/api/tutor/stats')
            ->assertStatus(401)
            ->assertJsonPath('code', 'SESSION_REVOKED');
    }

    public function test_password_reset_revokes_all_existing_sessions(): void
    {
        // 1. Device A logs in
        $loginA = $this->postJson('/api/login', [
            'email' => 'student.session@example.com',
            'password' => 'password123',
        ]);
        $loginA->assertStatus(200);
        $tokenA = $loginA->json('access_token');

        $this->resetSanctumGuard();

        // 2. Token A is valid before the reset
        $this->withToken($tokenA)->getJson('/api/user')->assertStatus(200);

        $this->resetSanctumGuard();

        // 3. Password is reset via the broker issued token
        $resetToken = \Illuminate\Support\Facades\Password::broker()->createToken($this->studentUser);
        $resetRes = $this->postJson('/api/reset-password', [
            'token' => $resetToken,
            'email' => 'student.session@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);
        $resetRes->assertStatus(200);

        // 4. The old token MUST be rejected after the reset
        $this->resetSanctumGuard();
        $this->withToken($tokenA)->getJson('/api/user')->assertStatus(401);

        $this->resetSanctumGuard();

        // 5. Logging in again still works (session marker was rotated, not destroyed)
        $loginB = $this->postJson('/api/login', [
            'email' => 'student.session@example.com',
            'password' => 'newpassword123',
        ]);
        $loginB->assertStatus(200);
    }

    public function test_logout_on_old_device_does_not_revoke_newer_session_on_other_device(): void
    {
        // 1. Device A logs in
        $loginA = $this->postJson('/api/login', [
            'email' => 'student.session@example.com',
            'password' => 'password123',
        ]);
        $tokenA = $loginA->json('access_token');

        $this->resetSanctumGuard();

        // 2. Device B logs in
        $loginB = $this->postJson('/api/login', [
            'email' => 'student.session@example.com',
            'password' => 'password123',
        ]);
        $tokenB = $loginB->json('access_token');

        $this->resetSanctumGuard();

        // 3. Device A calls logout
        $logoutA = $this->withToken($tokenA)->postJson('/api/logout');
        $logoutA->assertStatus(200);

        $this->resetSanctumGuard();

        // 4. Device B session MUST STILL BE ACTIVE and functional
        $userResB = $this->withToken($tokenB)->getJson('/api/user');
        $userResB->assertStatus(200)
            ->assertJsonPath('email', 'student.session@example.com');
    }
}
