<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Trusted proxies are env-driven via config/trustedproxy.php. With no
        // TRUSTED_PROXIES configured the app trusts NO proxies (forwarded
        // headers are ignored), which is the safe default for direct access.
        $middleware->append(\Illuminate\Http\Middleware\TrustProxies::class);

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'super_admin' => \App\Http\Middleware\EnsureUserIsSuperAdmin::class,
            'tutor' => \App\Http\Middleware\EnsureUserIsTutorOrAdmin::class,
            'crm' => \App\Http\Middleware\EnsureUserCanAccessCrm::class,
            'company' => \App\Http\Middleware\EnsureUserIsCompany::class,
            'single.session' => \App\Http\Middleware\ValidateSingleActiveSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function (\Illuminate\Http\Request $request) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
