/**
 * Shared types + parsing for Laravel paginator envelopes.
 *
 * Laravel serializes paginators FLAT: the item array lives under `data`
 * while pagination metadata lives alongside it as top-level
 * `current_page` / `last_page` / `per_page` / `total` (/`from`/`to`) keys —
 * there is no nested `meta` object. Screens must retain that metadata
 * (never reduce a paginated response to a bare array); the array-tolerant
 * branch below exists only for endpoints that legitimately still return
 * raw arrays.
 */
export interface PageMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

export interface PaginatedResponse<T> {
  data: T[]
  links?: Record<string, { url: string | null; label: string; active: boolean }[]>
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

export interface ParsedPage<T> {
  items: T[]
  meta: PageMeta | null
}

function readMeta(value: unknown): PageMeta | null {
  if (typeof value !== 'object' || value === null) return null
  const envelope = value as Record<string, unknown>
  if (
    typeof envelope.current_page !== 'number' ||
    typeof envelope.last_page !== 'number' ||
    typeof envelope.per_page !== 'number' ||
    typeof envelope.total !== 'number'
  ) {
    return null
  }
  return {
    current_page: envelope.current_page,
    last_page: envelope.last_page,
    per_page: envelope.per_page,
    total: envelope.total,
    from: typeof envelope.from === 'number' ? envelope.from : null,
    to: typeof envelope.to === 'number' ? envelope.to : null,
  }
}

/**
 * Extract items + retained pagination metadata from either a Laravel
 * paginator envelope or a legacy raw array. Unknown shapes yield an empty
 * list with no metadata (callers render their empty state).
 */
export function extractPage<T>(payload: unknown): ParsedPage<T> {
  if (Array.isArray(payload)) {
    return { items: payload as T[], meta: null }
  }

  if (typeof payload === 'object' && payload !== null) {
    const envelope = payload as { data?: unknown }
    if (Array.isArray(envelope.data)) {
      return { items: envelope.data as T[], meta: readMeta(payload) }
    }
  }

  return { items: [], meta: null }
}
