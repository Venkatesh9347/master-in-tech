<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateSingleActiveSession
{
    /**
     * Handle an incoming request.
     * Enforces the global single active session invariant across all roles.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        if (! $token || $token instanceof \Laravel\Sanctum\TransientToken) {
            return $next($request);
        }

        $tokenSessionId = null;
        if (isset($token->abilities) && is_array($token->abilities)) {
            foreach ($token->abilities as $ability) {
                if (is_string($ability) && str_starts_with($ability, 'session:')) {
                    $tokenSessionId = substr($ability, 8);
                    break;
                }
            }
        }

        $activeSessionId = \App\Models\User::where('id', $user->id)->value('current_session_id');

        // If the token carries a session identifier and it does not match the active session in DB
        if ($tokenSessionId !== null && $activeSessionId !== $tokenSessionId) {
            // Prune the revoked token
            $token->delete();

            return response()->json([
                'message' => 'Your session has expired because your account was signed in on another device.',
                'code' => 'SESSION_REVOKED',
            ], 401);
        }

        return $next($request);
    }
}
