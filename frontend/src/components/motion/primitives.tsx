/**
 * MasterInTech — shared motion primitives.
 *
 * One small, deliberately restrained set. Each exists because several pages
 * need it; nothing here is speculative.
 *
 * Rules baked into every primitive:
 * - Decorative animation never gates content. If JS or the observer fails the
 *   element stays visible (see the `mit-reveal` rules in styles/motion.css).
 * - No animation loops, no scroll handlers beyond one passive IntersectionObserver,
 *   no layout reads (getBoundingClientRect) per frame.
 * - prefers-reduced-motion is handled in CSS, so these components need no
 *   duplicated logic.
 */

import {
  useEffect,
  useRef,
  useState,
  type CSSProperties,
  type ElementType,
  type ReactNode,
} from 'react';

import { useMotionEnabled } from './hooks';

/* ------------------------------------------------------------------ */
/* StaggerContainer / StaggerItem                                     */
/* ------------------------------------------------------------------ */

/**
 * Parent for sequential reveals. Children stagger via a CSS custom property
 * index; motion.css clamps the effective delay so long lists never make a
 * user wait.
 */
export function StaggerContainer({
  children,
  className = '',
  step,
  as: Tag = 'div',
  ...rest
}: {
  children: ReactNode;
  className?: string;
  /** Override the per-item delay step, in ms. */
  step?: number;
  as?: ElementType;
} & Record<string, unknown>) {
  const style = step
    ? ({ '--mit-stagger': `${step}ms` } as CSSProperties)
    : undefined;

  return (
    <Tag className={`mit-stagger ${className}`} style={style} {...rest}>
      {children}
    </Tag>
  );
}

/** A single child inside a StaggerContainer. */
export function StaggerItem({
  children,
  index = 0,
  className = '',
  as: Tag = 'div',
  ...rest
}: {
  children: ReactNode;
  index?: number;
  className?: string;
  as?: ElementType;
} & Record<string, unknown>) {
  return (
    <Tag
      className={className}
      style={{ '--mit-i': index } as CSSProperties}
      data-testid="mit-stagger-item"
      {...rest}
    >
      {children}
    </Tag>
  );
}

/* ------------------------------------------------------------------ */
/* Reveal — scroll-triggered section entrance                         */
/* ------------------------------------------------------------------ */

/**
 * Fades a block up the first time it scrolls into view, then stops.
 * Static afterwards: nothing loops.
 *
 * Progressive enhancement is the important part. The element renders visible
 * on the server and until the observer is attached; only then is it marked
 * pending. A failed or unsupported observer leaves the content readable.
 */
export function Reveal({
  children,
  className = '',
  index = 0,
  /** Animate immediately instead of waiting for intersection. */
  immediate = false,
  as: Tag = 'div',
  ...rest
}: {
  children: ReactNode;
  className?: string;
  index?: number;
  immediate?: boolean;
  as?: ElementType;
} & Record<string, unknown>) {
  const ref = useRef<HTMLElement | null>(null);
  const enabled = useMotionEnabled();
  const [shown, setShown] = useState(false);

  useEffect(() => {
    if (!enabled) {
      // Reduced motion or motion disabled: show content, skip the transition.
      setShown(true);
      return;
    }
    if (immediate) {
      setShown(true);
      return;
    }

    const node = ref.current;
    if (!node || typeof IntersectionObserver === 'undefined') {
      setShown(true);
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            setShown(true);
            observer.disconnect(); // one-shot: no repeated work while scrolling
          }
        });
      },
      { threshold: 0.12, rootMargin: '0px 0px -8% 0px' },
    );

    observer.observe(node);
    return () => observer.disconnect();
  }, [enabled, immediate]);

  return (
    <Tag
      ref={ref as never}
      className={`mit-reveal ${className}`}
      data-mit-shown={shown ? 'true' : 'false'}
      data-mit-pending={enabled && !shown ? 'true' : 'false'}
      style={{ '--mit-i': index } as CSSProperties}
      {...rest}
    >
      {children}
    </Tag>
  );
}

/* Entrance helpers                                                   */
/* ------------------------------------------------------------------ */

/** Simple CSS-driven entrance. `variant` picks the motion.css keyframe. */
export function Entrance({
  children,
  variant = 'rise',
  className = '',
  delay = 0,
  as: Tag = 'div',
  ...rest
}: {
  children: ReactNode;
  variant?: 'fade' | 'rise' | 'slide' | 'scale';
  className?: string;
  delay?: number;
  as?: ElementType;
} & Record<string, unknown>) {
  return (
    <Tag
      className={`mit-enter-${variant} ${className}`}
      style={delay ? ({ animationDelay: `${delay}ms` } as CSSProperties) : undefined}
      {...rest}
    >
      {children}
    </Tag>
  );
}

/* ------------------------------------------------------------------ */
/* AnimatedPage — route-level transition                               */
/* ------------------------------------------------------------------ */

/**
 * Wraps a route view so navigation reads as a single, quick fade-up.
 *
 * Deliberately NOT an exit animation with a delay: an exit would hold the new
 * route back and break perceived performance. The previous view is left to
 * unmount immediately, which also keeps browser back/forward and deep links
 * behaving exactly as before.
 *
 * `key` is derived from the caller (usually location.pathname) so React
 * remounts on real navigations only.
 */
export function AnimatedPage({
  children,
  pathname,
  className = '',
}: {
  children: ReactNode;
  pathname?: string;
  className?: string;
}) {
  const enabled = useMotionEnabled();
  return (
    <div
      key={pathname}
      className={`${enabled ? 'mit-route-enter' : ''} ${className}`.trim()}
      data-testid="mit-animated-page"
    >
      {children}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* AnimatedCard / AnimatedButton / AnimatedInput                      */
/* ------------------------------------------------------------------ */

/** Card that lifts and glows on hover. Works on any element type. */
export function AnimatedCard({
  children,
  className = '',
  glow = false,
  as: Tag = 'div',
  ...rest
}: {
  children: ReactNode;
  className?: string;
  glow?: boolean;
  as?: ElementType;
} & Record<string, unknown>) {
  return (
    <Tag
      className={`${glow ? 'mit-hover-glow' : 'mit-hover-lift'} ${className}`.trim()}
      {...rest}
    >
      {children}
    </Tag>
  );
}

/**
 * Button with press feedback. Never removes a focus ring; `disabled` and
 * `aria-disabled` both suppress the press effect.
 */
export function AnimatedButton({
  children,
  className = '',
  ...rest
}: { children: ReactNode; className?: string } & Record<string, unknown>) {
  return (
    <button type="button" className={`mit-control ${className}`.trim()} {...rest}>
      {children}
    </button>
  );
}

/** Input with focus illumination plus optional validation affordance. */
export function AnimatedInput({
  state = 'idle',
  className = '',
  ...rest
}: {
  state?: 'idle' | 'invalid' | 'valid';
  className?: string;
} & Record<string, unknown>) {
  const validation =
    state === 'invalid' ? ' mit-invalid' : state === 'valid' ? ' mit-valid' : '';
  return <input className={`mit-input${validation} ${className}`.trim()} {...rest} />;
}

/* ------------------------------------------------------------------ */
/* Shimmer / Skeleton / GlowBorder                                    */
/* ------------------------------------------------------------------ */

/** Subtle travelling highlight for "live"/premium surfaces. */
export function Shimmer({
  children,
  className = '',
  ...rest
}: { children?: ReactNode; className?: string } & Record<string, unknown>) {
  return (
    <div className={`mit-shimmer ${className}`.trim()} {...rest}>
      {children}
    </div>
  );
}

/**
 * Loading placeholder. `lines` produces a stacked block so callers do not hand
 * write placeholder shapes.
 */
export function Skeleton({
  lines = 1,
  className = '',
  ...rest
}: { lines?: number; className?: string } & Record<string, unknown>) {
  return (
    <div className={`space-y-2 ${className}`.trim()} aria-hidden="true" {...rest}>
      {Array.from({ length: Math.max(1, lines) }, (_, i) => (
        <div
          key={i}
          className="mit-skeleton h-3 w-full"
          style={{ width: `${100 - (i % 3) * 18}%` }}
        />
      ))}
    </div>
  );
}

/** Panel with an illuminated border, used for focal/auth content. */
export function GlowBorder({
  children,
  className = '',
  ...rest
}: { children: ReactNode; className?: string } & Record<string, unknown>) {
  return (
    <div className={`mit-hover-glow rounded-2xl ${className}`.trim()} {...rest}>
      {children}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* AnimatedBackground                                                 */
/* ------------------------------------------------------------------ */

/**
 * Ambient decorative backdrop: soft drifting glows only. Marked
 * aria-hidden and pointer-events-none so it can never affect reading or
 * interaction. Continuous motion is disabled under reduced motion.
 */
export function AnimatedBackground({
  className = '',
  children,
}: {
  className?: string;
  children?: ReactNode;
}) {
  return (
    <div className={`mit-stage overflow-hidden ${className}`.trim()}>
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 -z-10"
        data-testid="mit-ambient-bg"
      >
        <div
          className="mit-ambient absolute left-1/2 top-[-18%] h-[42rem] w-[42rem] -translate-x-1/2 rounded-full blur-3xl"
          style={{
            background:
              'radial-gradient(circle, rgba(59,130,246,0.30) 0%, rgba(59,130,246,0) 68%)',
            ['--animation-duration' as string]: '9s',
          }}
        />
        <div
          className="mit-ambient absolute right-[-12%] top-[24%] h-[30rem] w-[30rem] rounded-full blur-3xl"
          style={{
            background:
              'radial-gradient(circle, rgba(139,92,246,0.26) 0%, rgba(139,92,246,0) 70%)',
            ['--animation-duration' as string]: '11s',
          }}
        />
        <div
          className="mit-float absolute bottom-[-14%] left-[8%] h-[24rem] w-[24rem] rounded-full blur-3xl"
          style={{
            background:
              'radial-gradient(circle, rgba(56,189,248,0.20) 0%, rgba(56,189,248,0) 70%)',
            ['--animation-duration' as string]: '13s',
          }}
        />
      </div>
      {children}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* RadialLoader                                                       */
/* ------------------------------------------------------------------ */

/**
 * Route-level loading state. Uses the same radial language as the auth
 * screens so the product feels like one system, at a size that does not
 * distract while a chunk loads.
 */
export function RadialLoader({ label = 'Loading' }: { label?: string }) {
  return (
    <div
      role="status"
      aria-live="polite"
      data-testid="mit-radial-loader"
      className="mit-stage relative flex min-h-[60vh] flex-col items-center justify-center gap-6 overflow-hidden"
    >
      <div className="relative grid h-40 w-40 place-items-center">
        <div className="absolute inset-0 rounded-full border border-slate-700/70" />
        <div className="mit-orbit absolute inset-0" aria-hidden="true">
          <span className="absolute left-1/2 top-0 h-2.5 w-2.5 -translate-x-1/2 rounded-full bg-blue-400" />
        </div>
        <div className="mit-orbit absolute inset-3" aria-hidden="true">
          <span className="absolute left-1/2 top-0 h-2 w-2 -translate-x-1/2 rounded-full bg-violet-400" />
        </div>
        <span className="text-xs font-bold tracking-[0.2em] text-blue-300">MIT</span>
      </div>
      <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{label}</p>
    </div>
  );
}
