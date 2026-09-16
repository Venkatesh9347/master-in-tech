<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VideoPlaybackSession;
use App\Services\GoogleAuthService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        return response()->json([
            'message' => 'Public registration is disabled. Student accounts are created by MasterInTech administration following counselling. Please submit an enquiry to get started.',
        ], 403);
    }

    /**
     * Phase 1: Mobile Authentication endpoint.
     * Searches for existing approved student by registered phone number,
     * and dispatches a 30-second hashed OTP.
     * Does NOT automatically create an account if phone is not registered.
     */
    public function mobileLogin(Request $request, OtpService $otpService): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:6|max:30',
        ]);

        $rawPhone = trim($request->input('phone'));
        $digitsOnly = preg_replace('/[^\d]/', '', $rawPhone);

        // Match user by exact phone or normalized digits
        $user = User::where(function ($q) use ($rawPhone, $digitsOnly) {
            $q->where('phone', $rawPhone)
                ->orWhere('phone', 'like', "%{$digitsOnly}");
        })->first();

        // HIGH-5: respond identically for known, unknown, and deactivated phones
        // so the endpoint cannot be used to enumerate registered numbers. The
        // generic response never returns raw phone (or email) information.
        $disabled = $user && in_array($user->status, ['disabled', 'inactive', 'suspended'], true);

        if (! $user || $disabled) {
            // Generic pre-auth token that is not tied to any registered account,
            // so it cannot be verified. Indistinguishable from a real dispatch.
            return response()->json([
                'message' => 'A 30-second verification code has been sent to your registered mobile number.',
                'requires_otp' => true,
                'temp_token' => $otpService->generateTempToken(),
                'masked_phone' => $otpService->maskPhone($rawPhone),
                'expires_in' => (int) config('auth.otp_expiry_seconds', 30),
                'resend_cooldown' => (int) config('auth.otp_resend_cooldown_seconds', 30),
            ]);
        }

        $otpPayload = $otpService->createOtpForUserViaMobile($user);

        return response()->json([
            'message' => 'A 30-second verification code has been sent to your registered mobile number.',
            'requires_otp' => true,
            'temp_token' => $otpPayload['temp_token'],
            'masked_phone' => $otpService->maskPhone($rawPhone),
            'expires_in' => $otpPayload['expires_in'],
            'resend_cooldown' => $otpPayload['resend_cooldown'],
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $email = strtolower(trim($request->email));
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // NEW-SEC-03: account-state failures must be indistinguishable from
        // bad credentials to an unauthenticated caller, otherwise a correct
        // password guess confirms the account exists, is disabled, or is
        // awaiting approval. Enforcement is unchanged — these accounts still
        // receive no token — only the visible message is normalized.
        $accountBlocked = in_array($user->status, ['disabled', 'inactive', 'suspended'], true)
            || ($user->isCompany() && (! $user->company || ! $user->company->isApproved()));

        if ($accountBlocked) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->startNewActiveSession('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    /**
     * Phase 1: Google Authentication endpoint.
     * Verifies Google token or exchanges authorization code, provisions/locates student user,
     * and dispatches a 30-second hashed OTP.
     * Does NOT return a Sanctum access_token.
     */
    public function googleLogin(Request $request, GoogleAuthService $googleAuthService, OtpService $otpService): JsonResponse
    {
        $request->validate([
            'credential' => 'nullable|string',
            'code' => 'nullable|string',
            'redirect_uri' => 'nullable|string',
        ]);

        $credential = $request->input('credential') ?? $request->input('code');

        if (! $credential) {
            return response()->json([
                'message' => 'The credential or code field is required.',
                'errors' => [
                    'credential' => ['Google authentication credential or authorization code is required.'],
                ],
            ], 422);
        }

        $user = $googleAuthService->verifyAndProvisionUser($credential, $request->input('redirect_uri'));
        $otpPayload = $otpService->createOtpForUser($user);

        return response()->json([
            'message' => 'Google authentication verified. A 30-second verification code has been sent to your email.',
            'requires_otp' => true,
            'temp_token' => $otpPayload['temp_token'],
            'email' => $otpPayload['email'],
            'masked_email' => $otpPayload['masked_email'],
            'expires_in' => $otpPayload['expires_in'],
            'resend_cooldown' => $otpPayload['resend_cooldown'],
        ]);
    }

    /**
     * Phase 1: Authoritative OTP Verification endpoint.
     * Validates 6-digit OTP within 30 seconds, deletes OTP,
     * and issues the Sanctum Bearer access token for the new active single session.
     */
    public function verifyOtp(Request $request, OtpService $otpService): JsonResponse
    {
        $request->validate([
            'temp_token' => 'required|string',
            'otp' => 'required|string|size:6',
        ]);

        $user = $otpService->verifyOtp($request->temp_token, $request->otp);
        $token = $user->startNewActiveSession('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Verification successful. Login complete.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    /**
     * Phase 1: Resend OTP endpoint.
     * Enforces 30-second resend cooldown, invalidates old OTP,
     * and sends a fresh 30-second OTP.
     */
    public function resendOtp(Request $request, OtpService $otpService): JsonResponse
    {
        $request->validate([
            'temp_token' => 'required|string',
        ]);

        $otpPayload = $otpService->resendOtp($request->temp_token);

        return response()->json([
            'message' => $otpPayload['message'],
            'temp_token' => $otpPayload['temp_token'],
            'email' => $otpPayload['email'],
            'masked_email' => $otpPayload['masked_email'],
            'expires_in' => $otpPayload['expires_in'],
            'resend_cooldown' => $otpPayload['resend_cooldown'],
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        // S-03: invalidate this user's HLS playback sessions on logout so a
        // bearer playback token cannot be replayed after logout within TTL.
        VideoPlaybackSession::where('user_id', $user->id)->delete();

        $token = $user->currentAccessToken();

        $tokenSessionId = null;
        if ($token && ! ($token instanceof \Laravel\Sanctum\TransientToken) && isset($token->abilities) && is_array($token->abilities)) {
            foreach ($token->abilities as $ability) {
                if (is_string($ability) && str_starts_with($ability, 'session:')) {
                    $tokenSessionId = substr($ability, 8);
                    break;
                }
            }
        }

        if ($token) {
            $token->delete();
        }

        // Only clear current_session_id if this token belonged to the active session
        if ($tokenSessionId && $user->current_session_id === $tokenSessionId) {
            $user->forceFill(['current_session_id' => null])->save();
        }

        return response()->json([
            'message' => 'Logout successful',
        ]);
    }
}
