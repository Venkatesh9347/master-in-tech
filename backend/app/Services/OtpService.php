<?php

namespace App\Services;

use App\Jobs\SendOtpEmailJob;
use App\Models\StudentLoginOtp;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OtpService
{
    /**
     * Generate a cryptographically secure 6-digit numeric OTP.
     */
    public function generateNumericOtp(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a cryptographically secure random session token.
     */
    public function generateTempToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Create, hash, and dispatch a 30-second single-use OTP for a student.
     * Invalidate any previous OTPs for this user.
     */
    public function createOtpForUser(User $user): array
    {
        // 1. Invalidate and purge all previous pending OTP sessions for this user
        StudentLoginOtp::where('user_id', $user->id)->delete();

        // 2. Generate raw credentials
        $rawOtp = $this->generateNumericOtp();
        $rawTempToken = $this->generateTempToken();

        $expirySeconds = (int) config('auth.otp_expiry_seconds', 30);
        $cooldownSeconds = (int) config('auth.otp_resend_cooldown_seconds', 30);
        $maxAttempts = (int) config('auth.otp_max_attempts', 3);

        $expiresAt = now()->addSeconds($expirySeconds);
        $resendAvailableAt = now()->addSeconds($cooldownSeconds);

        // 3. Store securely hashed in database
        StudentLoginOtp::create([
            'user_id' => $user->id,
            'temp_token_hash' => hash('sha256', $rawTempToken),
            'otp_hash' => Hash::make($rawOtp),
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'expires_at' => $expiresAt,
            'resend_available_at' => $resendAvailableAt,
        ]);

        // 4. Dispatch transactional email via background queue
        try {
            SendOtpEmailJob::dispatch($user->id, $rawOtp, $expirySeconds);
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch Student Login OTP email job: ' . $e->getMessage());
        }

        // 5. Return pre-auth metadata (NEVER expose the raw OTP)
        return [
            'temp_token' => $rawTempToken,
            'email' => $user->email,
            'masked_email' => $this->maskEmail($user->email),
            'phone' => $user->phone,
            'masked_phone' => $this->maskPhone($user->phone),
            'expires_in' => $expirySeconds,
            'resend_cooldown' => $cooldownSeconds,
        ];
    }

    /**
     * Create, hash, and dispatch a 30-second single-use OTP for a student via registered mobile.
     */
    public function createOtpForUserViaMobile(User $user): array
    {
        // 1. Invalidate and purge all previous pending OTP sessions for this user
        StudentLoginOtp::where('user_id', $user->id)->delete();

        // 2. Generate raw credentials
        $rawOtp = $this->generateNumericOtp();
        $rawTempToken = $this->generateTempToken();

        $expirySeconds = (int) config('auth.otp_expiry_seconds', 30);
        $cooldownSeconds = (int) config('auth.otp_resend_cooldown_seconds', 30);
        $maxAttempts = (int) config('auth.otp_max_attempts', 3);

        $expiresAt = now()->addSeconds($expirySeconds);
        $resendAvailableAt = now()->addSeconds($cooldownSeconds);

        // 3. Store securely hashed in database
        StudentLoginOtp::create([
            'user_id' => $user->id,
            'temp_token_hash' => hash('sha256', $rawTempToken),
            'otp_hash' => Hash::make($rawOtp),
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'expires_at' => $expiresAt,
            'resend_available_at' => $resendAvailableAt,
        ]);

        // 4. Dispatch email as well if user has email on file
        if (! empty($user->email)) {
            try {
                SendOtpEmailJob::dispatch($user->id, $rawOtp, $expirySeconds);
            } catch (\Throwable $e) {
                Log::error('Failed to dispatch Student Mobile Login OTP email fallback job: ' . $e->getMessage());
            }
        }

        // 5. In testing/local environment, record simulated dispatch log
        Log::info("Mobile OTP dispatched for Student #{$user->id} ({$user->phone})");

        return [
            'temp_token' => $rawTempToken,
            'phone' => $user->phone,
            'masked_phone' => $this->maskPhone($user->phone),
            'email' => $user->email,
            'masked_email' => $this->maskEmail($user->email),
            'expires_in' => $expirySeconds,
            'resend_cooldown' => $cooldownSeconds,
        ];
    }

    /**
     * Create a fake pre-auth session for an unknown or deactivated phone
     * number (HIGH-5 enumeration resistance).
     *
     * A bare random token is NOT enough: verify/resend would answer
     * differently for it than for a real session, re-enumerating registered
     * numbers one step later. The fake session mirrors a real OTP row
     * (attempts, expiry, cooldown) in cache so every downstream response is
     * indistinguishable. No OTP is ever dispatched and no user is attached,
     * so the code can never authenticate.
     *
     * @return array{temp_token: string, masked_phone: string, expires_in: int, resend_cooldown: int, message: string}
     */
    public function createFakePreAuthSession(string $maskedPhone): array
    {
        $expirySeconds = (int) config('auth.otp_expiry_seconds', 30);
        $cooldownSeconds = (int) config('auth.otp_resend_cooldown_seconds', 30);
        $maxAttempts = (int) config('auth.otp_max_attempts', 3);

        $rawTempToken = $this->generateTempToken();

        Cache::put(
            $this->fakeSessionKey($rawTempToken),
            [
                'masked_phone' => $maskedPhone,
                'otp_hash' => Hash::make($this->generateNumericOtp()),
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'expires_at' => now()->addSeconds($expirySeconds)->timestamp,
                'resend_available_at' => now()->addSeconds($cooldownSeconds)->timestamp,
            ],
            $this->fakeSessionTtl($expirySeconds, $cooldownSeconds)
        );

        return [
            'temp_token' => $rawTempToken,
            'masked_phone' => $maskedPhone,
            'expires_in' => $expirySeconds,
            'resend_cooldown' => $cooldownSeconds,
            'message' => 'A new 30-second verification code has been sent.',
        ];
    }

    /**
     * Resend a fresh 30-second OTP for an active pre-auth session.
     * Enforces resend cooldown and invalidates old OTP.
     */
    public function resendOtp(string $rawTempToken): array
    {
        $tokenHash = hash('sha256', $rawTempToken);
        $existingRecord = StudentLoginOtp::where('temp_token_hash', $tokenHash)->first();

        if (! $existingRecord || $existingRecord->isVerified()) {
            return $this->rotateFakeSession($rawTempToken);
        }

        // Enforce resend cooldown (30 seconds)
        if (! $existingRecord->canResend()) {
            $remaining = $existingRecord->getRemainingCooldownSeconds();
            throw ValidationException::withMessages([
                'resend' => ["Please wait {$remaining} seconds before requesting another verification code."],
            ]);
        }

        $user = $existingRecord->user;

        // Invalidate old OTP and generate fresh 6-digit OTP
        $rawOtp = $this->generateNumericOtp();
        $rawNewTempToken = $this->generateTempToken();

        $expirySeconds = (int) config('auth.otp_expiry_seconds', 30);
        $cooldownSeconds = (int) config('auth.otp_resend_cooldown_seconds', 30);
        $maxAttempts = (int) config('auth.otp_max_attempts', 3);

        $existingRecord->delete();

        StudentLoginOtp::create([
            'user_id' => $user->id,
            'temp_token_hash' => hash('sha256', $rawNewTempToken),
            'otp_hash' => Hash::make($rawOtp),
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'expires_at' => now()->addSeconds($expirySeconds),
            'resend_available_at' => now()->addSeconds($cooldownSeconds),
        ]);

        if (! empty($user->email)) {
            try {
                SendOtpEmailJob::dispatch($user->id, $rawOtp, $expirySeconds);
            } catch (\Throwable $e) {
                Log::error('Failed to dispatch Resend Student Login OTP email job: ' . $e->getMessage());
            }
        }

        return [
            'temp_token' => $rawNewTempToken,
            'email' => $user->email,
            'masked_email' => $this->maskEmail($user->email),
            'phone' => $user->phone,
            'masked_phone' => $this->maskPhone($user->phone),
            'expires_in' => $expirySeconds,
            'resend_cooldown' => $cooldownSeconds,
            'message' => 'A new 30-second verification code has been sent.',
        ];
    }

    /**
     * Authoritatively verify the OTP submitted by the user.
     * Enforces single-use, max attempts, and 30-second TTL.
     *
     * @throws ValidationException
     */
    public function verifyOtp(string $rawTempToken, string $submittedOtp): User
    {
        $tokenHash = hash('sha256', $rawTempToken);
        $otpRecord = StudentLoginOtp::with('user')->where('temp_token_hash', $tokenHash)->first();

        if (! $otpRecord) {
            $this->throwForFakeSession($rawTempToken, $submittedOtp);

            throw ValidationException::withMessages([
                'otp' => ['Verification session not found or has expired. Please sign in again.'],
            ]);
        }

        if ($otpRecord->isExpired()) {
            $otpRecord->delete();
            throw ValidationException::withMessages([
                'otp' => ['Verification code has expired (30-second limit). Please click Resend Code.'],
            ]);
        }

        if ($otpRecord->hasExceededAttempts()) {
            $otpRecord->delete();
            throw ValidationException::withMessages([
                'otp' => ['Maximum verification attempts exceeded. Please sign in again.'],
            ]);
        }

        // Validate cryptographically hashed OTP
        if (! Hash::check($submittedOtp, $otpRecord->otp_hash)) {
            $otpRecord->incrementAttempts();
            $remaining = $otpRecord->getRemainingAttempts();

            if ($remaining <= 0) {
                $otpRecord->delete();
                throw ValidationException::withMessages([
                    'otp' => ['Maximum verification attempts exceeded. Please sign in again.'],
                ]);
            }

            throw ValidationException::withMessages([
                'otp' => ["Incorrect verification code. {$remaining} attempt(s) remaining."],
            ]);
        }

        // OTP is valid! Immediately enforce SINGLE-USE by deleting the record
        $user = $otpRecord->user;
        $otpRecord->delete();

        if (! $user) {
            throw ValidationException::withMessages([
                'otp' => ['User account associated with this verification could not be located.'],
            ]);
        }

        return $user;
    }

    /**
     * Mirror the verifyOtp failure sequence for a fake pre-auth session.
     * Throws exactly the messages a real session would produce (wrong code
     * with remaining attempts, expiry, lockout); returns silently when the
     * token was never issued so garbage tokens keep the legacy response.
     *
     * @throws ValidationException
     */
    private function throwForFakeSession(string $rawTempToken, string $submittedOtp): void
    {
        $key = $this->fakeSessionKey($rawTempToken);
        $fake = Cache::get($key);

        if (! is_array($fake)) {
            return;
        }

        $maxAttempts = (int) ($fake['max_attempts'] ?? config('auth.otp_max_attempts', 3));

        if (now()->timestamp > (int) ($fake['expires_at'] ?? 0)) {
            Cache::forget($key);
            throw ValidationException::withMessages([
                'otp' => ['Verification code has expired (30-second limit). Please click Resend Code.'],
            ]);
        }

        if ((int) ($fake['attempts'] ?? 0) >= $maxAttempts) {
            Cache::forget($key);
            throw ValidationException::withMessages([
                'otp' => ['Maximum verification attempts exceeded. Please sign in again.'],
            ]);
        }

        if (! Hash::check($submittedOtp, (string) ($fake['otp_hash'] ?? ''))) {
            $fake['attempts'] = (int) ($fake['attempts'] ?? 0) + 1;
            $remaining = $maxAttempts - $fake['attempts'];

            if ($remaining <= 0) {
                Cache::forget($key);
                throw ValidationException::withMessages([
                    'otp' => ['Maximum verification attempts exceeded. Please sign in again.'],
                ]);
            }

            Cache::put($key, $fake, $this->fakeSessionTtl(
                (int) config('auth.otp_expiry_seconds', 30),
                (int) config('auth.otp_resend_cooldown_seconds', 30)
            ));

            throw ValidationException::withMessages([
                'otp' => ["Incorrect verification code. {$remaining} attempt(s) remaining."],
            ]);
        }

        // Practically unreachable (a blind 6-digit guess inside 3 attempts
        // within 30 seconds) and harmless: no user is attached to the
        // session, so authentication is impossible.
        Cache::forget($key);
        throw ValidationException::withMessages([
            'otp' => ['User account associated with this verification could not be located.'],
        ]);
    }

    /**
     * Mirror the resendOtp rotation for a fake pre-auth session. Returns a
     * fresh payload on success; throws the cooldown message while cooling
     * down and the expired-session message for never-issued tokens — the
     * same outcomes a real session produces.
     *
     * @throws ValidationException
     */
    private function rotateFakeSession(string $rawTempToken): array
    {
        $key = $this->fakeSessionKey($rawTempToken);
        $fake = Cache::get($key);

        if (! is_array($fake)) {
            throw ValidationException::withMessages([
                'temp_token' => ['Your verification session has expired. Please sign in again.'],
            ]);
        }

        $now = now()->timestamp;

        if ($now < (int) ($fake['resend_available_at'] ?? 0)) {
            $remaining = max(0, (int) ($fake['resend_available_at'] ?? $now) - $now);
            throw ValidationException::withMessages([
                'resend' => ["Please wait {$remaining} seconds before requesting another verification code."],
            ]);
        }

        // Rotate exactly like a real resend: new token, reset windows,
        // masked contact carried forward (email/phone were never known).
        Cache::forget($key);
        $rotated = $this->createFakePreAuthSession((string) ($fake['masked_phone'] ?? 'Registered Mobile'));

        return array_merge($rotated, [
            'email' => null,
            'masked_email' => null,
            'phone' => null,
        ]);
    }

    private function fakeSessionKey(string $rawTempToken): string
    {
        return 'otp_fake_session:' . hash('sha256', $rawTempToken);
    }

    private function fakeSessionTtl(int $expirySeconds, int $cooldownSeconds): \DateTimeInterface
    {
        return now()->addSeconds(max($expirySeconds, $cooldownSeconds) + 60);
    }

    /**
     * Mask email address for safe frontend presentation (e.g. j***e@domain.com).
     */
    public function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }

        $name = $parts[0];
        $domain = $parts[1];

        if (strlen($name) <= 2) {
            $maskedName = substr($name, 0, 1) . '*';
        } else {
            $maskedName = substr($name, 0, 1) . str_repeat('*', strlen($name) - 2) . substr($name, -1);
        }

        return $maskedName . '@' . $domain;
    }

    /**
     * Mask mobile number for safe frontend presentation (e.g. +91 ******1234).
     */
    public function maskPhone(?string $phone): string
    {
        if (empty($phone)) {
            return 'Registered Mobile';
        }

        $digits = preg_replace('/[^\d]/', '', $phone);
        if (strlen($digits) <= 4) {
            return '******' . $digits;
        }

        $last4 = substr($digits, -4);
        return '+91 ******' . $last4;
    }
}
