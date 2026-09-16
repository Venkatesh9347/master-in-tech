<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Services\Ai\Contracts\LlmProviderInterface::class, function () {
            $provider = config('ai.default_provider', 'stub');

            return match ($provider) {
                'stub' => new \App\Services\Ai\Providers\StubLlmProvider(),
                'openai' => new \App\Services\Ai\Providers\OpenAiProvider(),
                'ollama' => new \App\Services\Ai\Providers\OllamaProvider(),
                default => throw new \RuntimeException(
                    "Unsupported AI_PROVIDER [{$provider}]. Supported providers: \"stub\", \"openai\", \"ollama\"."
                ),
            };
        });

        $this->app->bind(\App\Services\Payment\PaymentProviderInterface::class, function () {
            return match (config('payment.default_provider', 'stub')) {
                'razorpay' => new \App\Services\Payment\Providers\RazorpayProvider(),
                default => new \App\Services\Payment\Providers\StubPaymentProvider(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureAuthRateLimiters();

        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');

            return $frontendUrl.'/reset-password/'
                .$token
                .'?email='.urlencode($user->email);
        });
    }

    private function configureAuthRateLimiters(): void
    {
        $testingBurst = app()->environment('testing') ? 60 : null;

        RateLimiter::for('auth-login', function (Request $request) use ($testingBurst) {
            return Limit::perMinute($testingBurst ?? 5)
                ->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('auth-otp-send', function (Request $request) use ($testingBurst) {
            $identity = strtolower((string) (
                $request->input('phone')
                ?: $request->input('email')
                ?: $request->input('credential')
                ?: $request->input('code')
                ?: $request->ip()
            ));

            return Limit::perMinute($testingBurst ?? 5)->by($identity.'|'.$request->ip());
        });

        RateLimiter::for('auth-otp-verify', function (Request $request) use ($testingBurst) {
            // Keyed by source IP alone (NOT per temp_token) so an attacker cannot
            // rotate fresh OTP sessions to bypass the per-source guess cap.
            return Limit::perMinute($testingBurst ?? 5)
                ->by('auth-otp-verify|'.$request->ip());
        });

        RateLimiter::for('auth-otp-resend', function (Request $request) use ($testingBurst) {
            return Limit::perMinute($testingBurst ?? 5)
                ->by((string) $request->input('temp_token').'|'.$request->ip());
        });

        RateLimiter::for('auth-forgot', function (Request $request) use ($testingBurst) {
            return Limit::perMinute($testingBurst ?? 5)
                ->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('public-submission', function (Request $request) use ($testingBurst) {
            return Limit::perMinute($testingBurst ?? 5)->by($request->ip());
        });

        RateLimiter::for('ai-chat', function (Request $request) use ($testingBurst) {
            $userId = $request->user()?->id ?? 'guest';

            return Limit::perMinute($testingBurst ?? 20)->by('ai-chat|'.$userId.'|'.$request->ip());
        });

        // Payment provider webhooks (public, signature-verified; bound by IP)
        RateLimiter::for('webhook', function (Request $request) use ($testingBurst) {
            return Limit::perMinute($testingBurst ?? 60)->by('webhook|'.$request->ip());
        });

        // Authenticated payment order creation (per user)
        RateLimiter::for('payment-order', function (Request $request) use ($testingBurst) {
            $userId = $request->user()?->id ?? 'guest';

            return Limit::perMinute($testingBurst ?? 20)->by('payment-order|'.$userId);
        });

        // Token-authorized HLS manifests/segments/keys (per IP; legit
        // playback needs ~10-15 req/min, scrapers hit far harder).
        RateLimiter::for('video-stream', function (Request $request) use ($testingBurst) {
            return Limit::perMinute($testingBurst ?? 60)->by('video-stream|'.$request->ip());
        });

        // Call recording file upload/download (per user; audio files)
        RateLimiter::for('crm-recordings', function (Request $request) use ($testingBurst) {
            $userId = $request->user()?->id ?? 'guest';

            return Limit::perMinute($testingBurst ?? 20)->by('crm-recordings|'.$userId);
        });

        // NEW-SEC-02: LMS progress writes (lesson start/complete/progress,
        // quiz start/submit, assignment submit, event registration). Light
        // single-row writes; keyed by user so one account cannot block
        // another and changing IPs does not bypass the cap.
        RateLimiter::for('lms-write', function (Request $request) use ($testingBurst) {
            $userId = $request->user()?->id ?? 'guest';

            return Limit::perMinute($testingBurst ?? 60)->by('lms-write|'.$userId);
        });

        // NEW-SEC-02: certificate issuance/download (PDF generation + row
        // minting under lock; rare legitimate use, keyed by user).
        RateLimiter::for('certificates', function (Request $request) use ($testingBurst) {
            $userId = $request->user()?->id ?? 'guest';

            return Limit::perMinute($testingBurst ?? 20)->by('certificates|'.$userId);
        });

        // NEW-SEC-02: LiveKit token minting (JWT + attendance writes;
        // rejoin bursts stay far below this, keyed by user).
        RateLimiter::for('livekit-token', function (Request $request) use ($testingBurst) {
            $userId = $request->user()?->id ?? 'guest';

            return Limit::perMinute($testingBurst ?? 30)->by('livekit-token|'.$userId);
        });

        // NEW-SEC-02: HLS playback authorization (mints a DB playback
        // session per call; players re-auth every few minutes, keyed by
        // user so token/session abuse cannot amplify storage writes).
        RateLimiter::for('playback-auth', function (Request $request) use ($testingBurst) {
            $userId = $request->user()?->id ?? 'guest';

            return Limit::perMinute($testingBurst ?? 30)->by('playback-auth|'.$userId);
        });
    }
}
