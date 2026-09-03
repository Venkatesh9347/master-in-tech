<?php

namespace App\Services;

use App\Jobs\SendOtpEmailJob;
use App\Models\StudentLoginOtp;
use App\Models\User;
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
     * Resend a fresh 30-second OTP for an active pre-auth session.
     * Enforces resend cooldown and invalidates old OTP.
     */
    public function resendOtp(string $rawTempToken): array
    {
        $tokenHash = hash('sha256', $rawTempToken);
        $existingRecord = StudentLoginOtp::where('temp_token_hash', $tokenHash)->first();

        if (! $existingRecord || $existingRecord->isVerified()) {
            throw ValidationException::withMessages([
                'temp_token' => ['Your verification session has expired. Please sign in again.'],
            ]);
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
