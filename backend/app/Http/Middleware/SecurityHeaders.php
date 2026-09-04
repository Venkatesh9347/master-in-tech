<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Apply baseline security headers to every response.
     *
     * Harden browser-side behaviors for the JSON API and served assets.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(self)');
        $response->headers->set('X-XSS-Protection', '0');

        // HSTS is only safe to advertise over HTTPS. When the app is fronted by
        // a TLS-terminating proxy (e.g. AWS Lightsail + nginx), $request->secure()
        // may not detect the scheme, so SECURITY_HSTS=1 forces it on regardless.
        $hstsForce = filter_var((string) config('security.hsts_force'), FILTER_VALIDATE_BOOL);
        if ($request->secure() || $hstsForce) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
