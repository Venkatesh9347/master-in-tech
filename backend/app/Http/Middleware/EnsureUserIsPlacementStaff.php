<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Placement authority for placement-operational endpoints.
 *
 * Placement Advisor is NOT an administrator. This middleware exists instead of
 * widening EnsureUserIsAdmin (which guards 179 routes covering user
 * administration, CMS, billing, role assignment and security settings) because
 * adding placement_advisor to that guard would silently promote it to full
 * platform administration.
 *
 * Two tiers pass:
 *   - placement_advisor : placement operational authority. Applied per-route to
 *                         the placement review/publish boundary only.
 *   - admin/super_admin  : administrative authority. Already passed these
 *                         routes via EnsureUserIsAdmin; accepted here so that
 *                         swapping the middleware on a route is behaviour
 *                         preserving for existing administrators.
 *
 * Everything else receives 403. Critically this does NOT grant user
 * administration, CMS, role assignment, security settings or CRM, because those
 * routes keep EnsureUserIsAdmin.
 */
class EnsureUserIsPlacementStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! ($user->canOperatePlacement() || $user->isAdmin())) {
            return response()->json([
                'message' => 'Unauthorized. Placement operations access required.',
            ], 403);
        }

        return $next($request);
    }
}