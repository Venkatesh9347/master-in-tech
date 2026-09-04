<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Resolve an optional, bounded result-set size from the `limit` query
     * parameter. Returns null (fetch all) when no `limit` is supplied, so the
     * existing API contract (a plain array) is preserved by default.
     *
     * When `?limit=` is provided it is clamped to a safe ceiling (default 1000)
     * to prevent unbounded full-table scans from a single request.
     */
    protected function limitCap(Request $request, int $ceiling = 1000): ?int
    {
        if (! $request->has('limit')) {
            return null;
        }

        $limit = (int) $request->input('limit');
        if ($limit <= 0) {
            return null;
        }

        return min($limit, $ceiling);
    }
}
