<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsCompany
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->canAccess('company')) {
            return response()->json([
                'message' => 'Unauthorized. Corporate partner access required.',
            ], 403);
        }

        if (! $user->company_id || ! $user->company) {
            return response()->json([
                'message' => 'No corporate profile associated with this account.',
            ], 403);
        }

        if (! $user->company->isApproved()) {
            return response()->json([
                'message' => 'Your corporate account is awaiting MasterInTech approval or has been suspended.',
                'company_status' => $user->company->status,
            ], 403);
        }

        return $next($request);
    }
}
