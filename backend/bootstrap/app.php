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

        // S-01: API exception sanitization (defense in depth, independent of
        // APP_DEBUG). API error responses must never expose stack traces,
        // absolute filesystem paths, exception class names, or source
        // file/line details. Intentional HTTP semantics (401/403/404/422/429
        // etc.) are preserved; only the debug envelope is removed. The
        // original exception is logged server-side for diagnostics.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            // Validation failures: keep the 422 contract (message + errors).
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 422);
            }

            // Authentication failures: keep the 401 contract.
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                $message = $e->getMessage() !== '' ? $e->getMessage() : 'Unauthenticated.';
                return response()->json(['message' => $message], 401);
            }

            // Authorization failures (Gate/Policy denies): keep 403.
            // Message is app-authored and safe to expose.
            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                $message = $e->getMessage() !== '' ? $e->getMessage() : 'Forbidden.';
                $status = ($e->hasStatus() && is_int($e->status())) ? $e->status() : 403;
                return response()->json(['message' => $message], $status);
            }

            // Missing models: generic 404. The framework default message
            // leaks the model class name, so it must not be echoed.
            // NOTE: kept as defense in depth; in practice the framework
            // converts these to NotFoundHttpException (handled next) before
            // render callbacks run.
            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return response()->json(['message' => 'Not found.'], 404);
            }

            // S-01b: findOrFail/firstOrFail (and implicit binding) failures
            // arrive here as NotFoundHttpException carrying the framework's
            // "No query results for model [App\Models\X]" message, because
            // prepareException() converts ModelNotFoundException first. Only
            // normalize those back to a generic 404 — intentional abort(404)
            // calls with app-authored messages keep their contract below.
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                $previous = $e->getPrevious();

                if ($previous instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                    || str_contains($e->getMessage(), 'No query results for model')) {
                    return response()->json(['message' => 'Not found.'], 404);
                }
            }

            // API unauthenticated without a JSON Accept header: the auth
            // middleware attempts a browser-style redirect to a `login`
            // route that does not exist in this API-only app. Answer JSON
            // 401 for api/* requests instead of a 500. Matched exactly so
            // genuine routing bugs elsewhere still surface as 500s.
            if ($e instanceof \Symfony\Component\Routing\Exception\RouteNotFoundException
                && $request->is('api/*')
                && $e->getMessage() === 'Route [login] not defined.') {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            // HTTP exceptions (abort(), throttling, method-not-allowed,
            // maintenance, route 404s): preserve status code, message, and
            // headers (e.g. Retry-After). Only 5xx messages are replaced —
            // they may carry internals — while the status code is kept.
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                $status = $e->getStatusCode();
                if ($status >= 500) {
                    \Illuminate\Support\Facades\Log::error('api.exception', [
                        'status' => $status,
                        'exception' => get_class($e),
                    ]);
                    return response()->json(['message' => 'Server Error'], $status);
                }
                $message = $e->getMessage() !== '' ? $e->getMessage() : 'Error';
                return response()->json(['message' => $message], $status, $e->getHeaders());
            }

            // Anything else (database faults, runtime errors, ...): log
            // server-side for diagnostics, return a generic 500. Never
            // serialize the original Throwable to the client.
            \Illuminate\Support\Facades\Log::error('api.exception', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Server Error'], 500);
        });
    })->create();
