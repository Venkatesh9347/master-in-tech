import { useEffect } from 'react'

/**
 * B10 — route-driven document metadata for a client-rendered SPA.
 *
 * The router has no per-route head component and the project has no SEO
 * dependency, so a single hook driven by a declarative route table is the
 * smallest maintainable mechanism. Routes opt in by calling the hook; nothing
 * is duplicated per page component.
 *
 * The static tags in index.html stay in place as the crawler-visible baseline
 * (a JS-driven SPA cannot expose per-route tags to a non-executing crawler).
 * This hook keeps the live document consistent for users and assistive tech.
 */

export interface PageMeta {
  /** Browser/tab title, excluding the site suffix. */
  title?: string
  /** Meta description. Falls back to the site default. */
  description?: string
  /** `og:type` for this route. */
  type?: 'website' | 'article'
  /**
   * Keep this route's tags out of social/debug surfaces. Used for anything
   * behind authentication so private information is never emitted into a
   * shareable or cacheable metadata field.
   */
  private?: boolean
}

const SITE_NAME = 'MasterInTech'

const DEFAULT_DESCRIPTION =
  'MasterInTech teaches in-demand tech skills through mentor-led bootcamps, structured industry curriculums, hands-on capstones and verifiable career credentials.'

/**
 * Title suffixes for sections a visitor may land on directly. Routes without an
 * entry fall back to the bare site name.
 */
const TITLE_SUFFIXES: Record<string, string> = {
  '': 'Technology Education',
  courses: 'Course Catalog',
  events: 'Events',
  about: 'About Us',
  contact: 'Contact',
  instructors: 'Instructors',
  resources: 'Resources',
  placements: 'Placements',
  'corporate-partner': 'Corporate Partner',
  faq: 'FAQ',
  'verify-certificate': 'Verify Certificate',
  login: 'Sign In',
  register: 'Create Account',
  'forgot-password': 'Reset Password',
  'reset-password': 'Reset Password',
}

/**
 * Explicit per-path overrides, matched as prefix patterns against the pathname.
 * Order matters: the first matching entry wins, so more specific paths are
 * listed before broader ones.
 *
 * `private: true` means the route sits behind authentication. Its tags stay
 * generic and carry no description, so no authenticated content can leak into a
 * shared, cached or crawled metadata field.
 */
const ROUTE_META: Array<{ match: RegExp; meta: PageMeta }> = [
  // Authenticated surfaces first (most specific prefixes).
  { match: /^\/student\/certificates\//, meta: { title: 'My Certificate', private: true } },
  { match: /^\/student\/checkout\//, meta: { title: 'Checkout', private: true } },
  { match: /^\/student\/courses\/[^/]+\/lessons$/, meta: { title: 'Lessons', private: true } },
  { match: /^\/student\/courses\/[^/]+\/live\//, meta: { title: 'Live Class', private: true } },
  { match: /^\/student\/classroom\//, meta: { title: 'Classroom', private: true } },
  { match: /^\/student\/class-sessions\//, meta: { title: 'Class Session', private: true } },
  { match: /^\/student\/mock-interview/, meta: { title: 'Mock Interview', private: true } },
  { match: /^\/student\/events$/, meta: { title: 'My Events', private: true } },
  { match: /^\/student\/courses\//, meta: { title: 'My Course', private: true } },
  { match: /^\/student\/profile$/, meta: { title: 'My Profile', private: true } },
  { match: /^\/student$/, meta: { title: 'Student Dashboard', private: true } },
  { match: /^\/tutor(\/|$)/, meta: { title: 'Tutor Console', private: true } },
  { match: /^\/company(\/|$)/, meta: { title: 'Company Portal', private: true } },

  // Public detail routes.
  { match: /^\/courses\/[^/]+$/, meta: { title: 'Course Details', type: 'article' } },
  { match: /^\/events\/[^/]+$/, meta: { title: 'Event Details', type: 'article' } },
  { match: /^\/verify-certificate\//, meta: { title: 'Verify Certificate' } },

  // Administrative surface: private, no descriptive tags.
  { match: /^\/admin(\/|$)/, meta: { title: 'Admin Console', private: true } },
  { match: /^\/ai-assistant$/, meta: { title: 'AI Assistant', private: true } },
]

/**
 * Resolves the metadata for a pathname.
 *
 * Unmatched public routes fall back to the section table in `usePageMeta`, so
 * adding a page does not require editing this function.
 */
export function routeMetaFor(pathname: string): PageMeta {
  for (const { match, meta } of ROUTE_META) {
    if (match.test(pathname)) return meta
  }
  return {}
}

function setMeta(selector: string, attr: 'name' | 'property', key: string, content: string) {
  let el = document.head.querySelector<HTMLMetaElement>(selector)
  if (!el) {
    el = document.createElement('meta')
    el.setAttribute(attr, key)
    document.head.appendChild(el)
  }
  el.setAttribute('content', content)
}

function describe(pathname: string): string | undefined {
  if (pathname === '/' || pathname === '') return undefined
  const segment = pathname.split('/').filter(Boolean)[0] ?? ''
  return TITLE_SUFFIXES[segment]
}

/**
 * Applies title, description and Open Graph tags for the current route.
 *
 * @param pathname  `location.pathname` — passed in rather than read from a
 *                  router hook so the hook works under any router and is
 *                  trivially testable.
 * @param meta      Route-specific overrides. `private: true` suppresses the
 *                  description and OG description.
 */
export function usePageMeta(pathname: string, meta: PageMeta = {}) {
  const isPrivate = meta.private === true

  useEffect(() => {
    const suffix = describe(pathname)
    // `meta.title` holds only the suffix, so a bare "My Course" never reaches
    // the browser tab or a share sheet.
    const title = meta.title
      ? `${SITE_NAME} — ${meta.title}`
      : suffix
        ? `${SITE_NAME} — ${suffix}`
        : `${SITE_NAME} — Technology Education`

    document.title = title

    const description = isPrivate ? undefined : meta.description ?? DEFAULT_DESCRIPTION

    setMeta('meta[name="description"]', 'name', 'description', description ?? DEFAULT_DESCRIPTION)

    setMeta('meta[property="og:title"]', 'property', 'og:title', title)
    setMeta('meta[property="og:site_name"]', 'property', 'og:site_name', SITE_NAME)
    setMeta('meta[property="og:type"]', 'property', 'og:type', meta.type ?? 'website')

    if (isPrivate) {
      // A private path can embed identifiers (certificate codes, session ids).
      // Publishing it as og:url would republish user data, so the tag is
      // removed rather than left stale from the previous public route.
      document.head.querySelector('meta[property="og:url"]')?.remove()
    } else {
      setMeta('meta[property="og:url"]', 'property', 'og:url', `/${pathname.replace(/^\/+/, '')}`)
    }

    if (description) {
      setMeta('meta[property="og:description"]', 'property', 'og:description', description)
    } else {
      // Never leave a stale route description behind on a private screen.
      document.head.querySelector('meta[property="og:description"]')?.remove()
    }
  }, [pathname, meta.title, meta.description, meta.type, isPrivate])
}