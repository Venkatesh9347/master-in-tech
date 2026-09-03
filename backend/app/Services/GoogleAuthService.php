<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoogleAuthService
{
    /**
     * Verify a Google ID Token or Authorization Code with Google OAuth servers and find or create the student account.
     *
     * @throws ValidationException
     */
    public function verifyAndProvisionUser(string $credential, ?string $redirectUri = null): User
    {
        $payload = $this->verifyIdToken($credential, $redirectUri);

        $googleId = $payload['sub'] ?? null;
        $email = strtolower(trim($payload['email'] ?? ''));
        $name = trim($payload['name'] ?? 'Student');
        $avatar = $payload['picture'] ?? null;

        if (empty($email)) {
            throw ValidationException::withMessages([
                'credential' => ['Google authentication failed: email address not provided by Google.'],
            ]);
        }

        // Look for existing user by google_id or email
        $user = null;
        if ($googleId) {
            $user = User::where('google_id', $googleId)->first();
        }

        if (! $user) {
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        }

        if (! $user) {
            throw ValidationException::withMessages([
                'credential' => ['Your account is not registered for student access. Please contact MasterInTech.'],
            ]);
        }

        if (isset($user->status) && in_array($user->status, ['disabled', 'inactive', 'suspended'], true)) {
            throw ValidationException::withMessages([
                'credential' => ['Your student account has been deactivated. Please contact MasterInTech support.'],
            ]);
        }

        // Update existing user's google info if missing
        $updates = [];
        if (! $user->google_id && $googleId) {
            $updates['google_id'] = $googleId;
        }
        if (! $user->avatar && $avatar) {
            $updates['avatar'] = $avatar;
        }
        if ($user->email_verified_at === null) {
            $updates['email_verified_at'] = now();
        }
        if (! empty($updates)) {
            $user->update($updates);
        }

        return $user;
    }

    /**
     * Authoritatively verify Google ID token or exchange authorization code.
     */
    public function verifyIdToken(string $credential, ?string $redirectUri = null): array
    {
        // 1. Check for mock/testing token in non-production environments
        if (app()->environment('local', 'testing') && str_starts_with($credential, 'test_mock_google_token_')) {
            $parts = explode(':', $credential);
            $testEmail = $parts[1] ?? 'test.student@example.com';
            $testName = $parts[2] ?? 'Test Student';
            $testSub = $parts[3] ?? 'mock_google_id_' . md5($testEmail);

            return [
                'sub' => $testSub,
                'email' => $testEmail,
                'email_verified' => true,
                'name' => $testName,
                'picture' => 'https://ui-avatars.com/api/?name=' . urlencode($testName),
            ];
        }

        // 2. If it is an authorization code (not standard JWT 2-dot format), attempt code exchange
        if (substr_count($credential, '.') !== 2 && (config('services.google.client_secret') || app()->environment('local', 'testing'))) {
            try {
                $clientId = config('services.google.client_id');
                $clientSecret = config('services.google.client_secret');
                $targetRedirectUri = $redirectUri ?? config('services.google.redirect') ?? url('/login');

                $tokenResponse = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                    'code' => $credential,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $targetRedirectUri,
                    'grant_type' => 'authorization_code',
                ]);

                if ($tokenResponse->successful()) {
                    $tokenData = $tokenResponse->json();

                    // Retrieve authoritative user identity using access token from Google UserInfo
                    if (! empty($tokenData['access_token'])) {
                        $userInfoResponse = Http::withToken($tokenData['access_token'])->timeout(10)->get('https://www.googleapis.com/oauth2/v3/userinfo');
                        if ($userInfoResponse->successful()) {
                            $userData = $userInfoResponse->json();
                            if (! empty($userData['email'])) {
                                return [
                                    'sub' => $userData['sub'] ?? $userData['id'] ?? md5($userData['email']),
                                    'email' => strtolower(trim($userData['email'])),
                                    'email_verified' => $userData['email_verified'] ?? true,
                                    'name' => $userData['name'] ?? 'Student',
                                    'picture' => $userData['picture'] ?? null,
                                ];
                            }
                        }
                    }

                    if (! empty($tokenData['id_token'])) {
                        $credential = $tokenData['id_token'];
                    }
                } else {
                    Log::warning('Google authorization code exchange failed', [
                        'status' => $tokenResponse->status(),
                        'body' => $tokenResponse->body(),
                    ]);

                    throw ValidationException::withMessages([
                        'credential' => ['Google authorization code exchange failed: ' . ($tokenResponse->json('error_description') ?? 'invalid or expired code.')],
                    ]);
                }
            } catch (ValidationException $ve) {
                throw $ve;
            } catch (\Throwable $e) {
                Log::warning('Exception during Google authorization code exchange: ' . $e->getMessage());

                throw ValidationException::withMessages([
                    'credential' => ['Failed to authenticate with Google: ' . $e->getMessage()],
                ]);
            }
        }

        // 3. Query Google's token verification API for ID token
        try {
            $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $credential,
            ]);

            if (! $response->successful()) {
                Log::warning('Google token verification failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw ValidationException::withMessages([
                    'credential' => ['The provided Google authentication token is invalid or has expired.'],
                ]);
            }

            $data = $response->json();

            // Validate Google audience (client_id) if configured
            $configuredClientId = config('services.google.client_id');
            if ($configuredClientId && ! empty($data['aud']) && $data['aud'] !== $configuredClientId) {
                Log::warning('Google token audience mismatch', [
                    'expected' => $configuredClientId,
                    'received' => $data['aud'],
                ]);
                throw ValidationException::withMessages([
                    'credential' => ['Google authentication token was issued for an unauthorized client.'],
                ]);
            }

            // Ensure email is verified by Google
            if (empty($data['email_verified']) || ($data['email_verified'] !== true && $data['email_verified'] !== 'true')) {
                throw ValidationException::withMessages([
                    'credential' => ['Google account email is unverified. Please verify your email with Google.'],
                ]);
            }

            return $data;
        } catch (ValidationException $ve) {
            throw $ve;
        } catch (\Throwable $e) {
            Log::error('Exception during Google token verification: ' . $e->getMessage());

            throw ValidationException::withMessages([
                'credential' => ['Unable to verify Google credentials at this time. Please try again.'],
            ]);
        }
    }
}
