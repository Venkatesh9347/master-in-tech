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
                'openai' => new \App\Services\Ai\Providers\OpenAiProvider(),
                default => new \App\Services\Ai\Providers\StubLlmProvider(),
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
            return Limit::perMinute($testingBurst ?? 10)
                ->by((string) $request->input('temp_token').'|'.$request->ip());
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
    }
}
