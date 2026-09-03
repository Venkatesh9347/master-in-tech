<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserCanAccessCrm
{
    /**
     * Handle an incoming request for CRM operations.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, ['admin', 'super_admin', 'counsellor'], true)) {
            return response()->json([
                'message' => 'Unauthorized. CRM and Admissions access required.',
            ], 403);
        }

        return $next($request);
    }
}
