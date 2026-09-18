import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import API from '../services/api'
import { extractPage, type PageMeta } from '../types/pagination'

export type QueryParams = Record<string, string | number | boolean | undefined | null>

export interface PagedQueryResult<T> {
  items: T[]
  meta: PageMeta | null
  loading: boolean
  error: string | null
  page: number
  perPage: number
  setPage: (page: number) => void
  reload: () => void
}

interface UsePagedQueryOptions<T> {
  perPage?: number
  /** Fired on success (e.g. to refresh a selected row from the new page). */
  onSuccess?: (items: T[], meta: PageMeta | null) => void
  /** Message shown when the request fails. */
  errorMessage?: string
  onError?: (message: string, error: unknown) => void
}

/**
 * Server-driven paginated fetching over the shared API client.
 *
 * - Appends `page` + `per_page` to the caller-supplied filter params.
 * - Any filter/search/sort change automatically resets to page 1 (single
 *   request — no duplicate fetch with the stale page).
 * - Out-of-range pages (e.g. rows deleted elsewhere) recover to the last
 *   valid page instead of sticking on an empty page.
 * - Out-of-order responses are ignored via a request id guard.
 */
export function usePagedQuery<T>(
  baseUrl: string,
  params: QueryParams,
  options: UsePagedQueryOptions<T> = {}
): PagedQueryResult<T> {
  const { perPage = 15, errorMessage = 'Failed to load results.' } = options

  const [items, setItems] = useState<T[]>([])
  const [meta, setMeta] = useState<PageMeta | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [page, setPage] = useState(1)
  const [reloadNonce, setReloadNonce] = useState(0)

  // Stable serialization of filter params (sorted keys, blanks dropped).
  // A primitive string dep: identical filters never retrigger the effect.
  // `serializedParams` is a plain render-scope string so the memo dep list
  // stays an array of simple expressions; the memo still recomputes exactly
  // when the serialized filters change.
  const serializedParams = JSON.stringify(params, Object.keys(params).sort())
  const paramsKey = useMemo(() => {
    const entries = Object.keys(params)
      .sort()
      .reduce<Record<string, string>>((acc, key) => {
        const value = params[key]
        if (value !== undefined && value !== null && value !== '') {
          acc[key] = String(value)
        }
        return acc
      }, {})
    return JSON.stringify(entries)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [baseUrl, serializedParams])

  const callbacksRef = useRef(options)

  // Keep the latest callbacks without retriggering the fetch effect. Written
  // in an effect (never during render); every read happens in async promise
  // handlers, which always run after effects, so the ref is always current.
  useEffect(() => {
    callbacksRef.current = options
  })

  const prevKeyRef = useRef(paramsKey)
  const requestIdRef = useRef(0)
  const servedRef = useRef<{ key: string; page: number; nonce: number } | null>(null)

  const reload = useCallback(() => {
    setReloadNonce((nonce) => nonce + 1)
  }, [])

  useEffect(() => {
    // Filter/search/sort changed: serve page 1 (and sync state for the UI).
    let effectivePage = page
    if (prevKeyRef.current !== paramsKey) {
      prevKeyRef.current = paramsKey
      effectivePage = 1
      if (page !== 1) {
        setPage(1)
      }
    }

    // The state sync above re-runs this effect; skip the duplicate fetch
    // for the key+page+nonce combination already served.
    const served = servedRef.current
    if (served && served.key === paramsKey && served.page === effectivePage && served.nonce === reloadNonce) {
      return
    }
    servedRef.current = { key: paramsKey, page: effectivePage, nonce: reloadNonce }

    const query = new URLSearchParams(JSON.parse(paramsKey) as Record<string, string>)
    query.set('page', String(effectivePage))
    query.set('per_page', String(perPage))

    let cancelled = false
    const requestId = requestIdRef.current + 1
    requestIdRef.current = requestId

    setLoading(true)
    setError(null)

    API.get<unknown>(`${baseUrl}?${query.toString()}`)
      .then((res) => {
        if (cancelled || requestIdRef.current !== requestId) return
        const { items: list, meta: pageMeta } = extractPage<T>(res.data)

        // Records vanished elsewhere: recover to the last valid page.
        if (pageMeta && pageMeta.last_page > 0 && effectivePage > pageMeta.last_page) {
          setPage(pageMeta.last_page)
          return
        }

        setItems(list)
        setMeta(pageMeta)
        callbacksRef.current.onSuccess?.(list, pageMeta)
      })
      .catch((err: unknown) => {
        if (cancelled || requestIdRef.current !== requestId) return
        setError(errorMessage)
        callbacksRef.current.onError?.(errorMessage, err)
      })
      .finally(() => {
        if (!cancelled && requestIdRef.current === requestId) {
          setLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [baseUrl, paramsKey, page, perPage, reloadNonce, errorMessage])

  return { items, meta, loading, error, page, perPage, setPage, reload }
}
