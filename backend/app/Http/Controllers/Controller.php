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

    /**
     * Resolve a bounded page size from the `per_page` query parameter for
     * Laravel paginator responses. Always returns a safe positive integer
     * clamped to the ceiling so a single request can never scan an
     * unbounded table, regardless of client input.
     */
    protected function perPage(Request $request, int $default = 15, int $max = 100): int
    {
        $perPage = (int) $request->input('per_page', $default);
        if ($perPage < 1) {
            return $default;
        }

        return min($perPage, $max);
    }
}
