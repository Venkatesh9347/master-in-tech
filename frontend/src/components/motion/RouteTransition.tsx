/**
 * MasterInTech — route transition shell.
 *
 * Wraps the router outlet so every navigation reads as one quick, coherent
 * fade-up instead of a hard cut. Mounted once inside BrowserRouter.
 *
 * Design constraints honoured here:
 * - No exit animation with a delay. Holding the outgoing view would make the
 *   product feel slower and could mask the incoming content. The previous view
 *   unmounts immediately; only the incoming view animates.
 * - No white flash: the shell owns the background, and the animating wrapper
 *   never changes height, so there is no layout jump.
 * - Fast enough for production (300ms) and non-blocking.
 * - Does not touch history, so browser back/forward and deep links behave
 *   exactly as before — the router is untouched.
 * - Under prefers-reduced-motion the wrapper renders with no animation class,
 *   so the page simply appears.
 *
 * Suspense stays OUTSIDE this wrapper, matching the existing architecture: a
 * lazy route shows the route-level fallback, then fades in once resolved.
 */

import { useLocation } from 'react-router-dom';
import { AnimatedPage } from './primitives';

export default function RouteTransition({ children }: { children: React.ReactNode }) {
  const { pathname } = useLocation();

  return (
    <AnimatedPage pathname={pathname} className="min-h-screen">
      {children}
    </AnimatedPage>
  );
}