import type { PageMeta } from '../types/pagination'

interface PaginationProps {
  meta: PageMeta
  onPageChange: (page: number) => void
  /** Accessible label for the navigation landmark. */
  label?: string
}

/**
 * Compact page-button list with first/last boundaries: always shows page 1
 * and the last page, plus a window around the current page.
 */
function pageNumbers(current: number, last: number): (number | '…')[] {
  if (last <= 7) {
    return Array.from({ length: last }, (_, i) => i + 1)
  }

  const window: (number | '…')[] = [1]
  const start = Math.max(2, current - 1)
  const end = Math.min(last - 1, current + 1)

  if (start > 2) window.push('…')
  for (let page = start; page <= end; page += 1) window.push(page)
  if (end < last - 1) window.push('…')
  window.push(last)

  return window
}

const buttonClass =
  'min-w-8 px-2 py-1 rounded-lg text-xs font-bold transition disabled:opacity-40 disabled:cursor-not-allowed'

export default function Pagination({ meta, onPageChange, label = 'Pagination' }: PaginationProps) {
  const { current_page: current, last_page: last, total, from, to } = meta

  if (last < 1 || total === 0) return null

  const statusText =
    from !== null && to !== null
      ? `Showing ${from}–${to} of ${total} results. Page ${current} of ${last}.`
      : `Page ${current} of ${last}. ${total} results total.`

  return (
    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mt-4">
      <p className="text-xs text-slate-400" role="status" aria-live="polite">
        {statusText}
      </p>
      <nav aria-label={label}>
        <div className="flex items-center gap-1">
          <button
            type="button"
            className={`${buttonClass} bg-slate-100 text-slate-700 hover:bg-slate-200`}
            disabled={current <= 1}
            onClick={() => onPageChange(current - 1)}
            aria-label="Go to previous page"
          >
            ‹ Prev
          </button>
          {pageNumbers(current, last).map((page, index) =>
            page === '…' ? (
              <span key={`ellipsis-${index}`} className="px-1 text-xs text-slate-400" aria-hidden="true">
                …
              </span>
            ) : (
              <button
                key={page}
                type="button"
                className={`${buttonClass} ${
                  page === current
                    ? 'bg-blue-600 text-white'
                    : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                }`}
                onClick={() => onPageChange(page)}
                aria-label={`Go to page ${page}`}
                aria-current={page === current ? 'page' : undefined}
              >
                {page}
              </button>
            )
          )}
          <button
            type="button"
            className={`${buttonClass} bg-slate-100 text-slate-700 hover:bg-slate-200`}
            disabled={current >= last}
            onClick={() => onPageChange(current + 1)}
            aria-label="Go to next page"
          >
            Next ›
          </button>
        </div>
      </nav>
    </div>
  )
}
