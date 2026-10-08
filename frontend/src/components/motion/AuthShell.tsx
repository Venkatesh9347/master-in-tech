/**
 * MasterInTech — authentication shell.
 *
 * The reference visual language applied to sign-in surfaces: a dark modern
 * stage with a large radial segmented animation surrounding a single central
 * panel that holds the real form.
 *
 * Priorities, in order:
 *  1. The form stays the focal point and stays easy to use.
 *  2. Radial segments are decorative, aria-hidden and pointer-events-none, so
 *     they can never intercept a click, focus or text selection.
 *  3. Nothing is hidden without JavaScript — the panel and its contents render
 *     fully visible; only motion classes are gated.
 *  4. prefers-reduced-motion stops the ring and the ambient glow entirely
 *     (handled in styles/motion.css).
 *
 * No authentication logic, API call or field is defined here. Pages pass their
 * own existing form as `children`, so behaviour cannot change.
 */

import type { ReactNode } from 'react';
import Navbar from '../Navbar';
import RadialAnimation from './RadialAnimation';

export interface AuthShellProps {
  children: ReactNode;
  /** Screen heading. */
  title: string;
  /** Supporting line under the heading. */
  subtitle?: string;
  /** Small glyph or icon shown above the heading. */
  glyph?: ReactNode;
  /** Segment count for the surrounding ring. */
  segments?: number;
  /** Ring diameter in px. Sized to clear the panel so the circle stays whole. */
  size?: number;
  /** Rendered under the form, e.g. "Don't have an account? Register". */
  footer?: ReactNode;
  /**
   * Panel treatment.
   *
   * 'light' keeps the existing white auth card exactly as authored, and is the
   * default for every current screen: their form internals use slate-900-on-white
   * text, so flipping the card to dark would mean re-colouring hundreds of lines
   * of form markup and would risk silent contrast failures.
   *
   * A bright focal card inside a dark animated surround is also the reference's
   * own composition. 'glass' exists for any future dark-native auth screen.
   */
  variant?: 'light' | 'glass';
}

export default function AuthShell({
  children,
  title,
  subtitle,
  glyph = (
    <span className="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-50 text-2xl text-blue-600 shadow-inner">
      🎓
    </span>
  ),
  segments = 56,
  size = 860,
  footer,
  variant = 'light',
}: AuthShellProps) {
  return (
    <div className="mit-stage relative flex min-h-screen flex-col overflow-hidden">
      <Navbar />

      {/* Decorative radial surround. Behind the panel, never interactive.
          The ring is sized larger than the panel so the segments read as a full
          circle framing the form rather than being clipped behind it. */}
      <RadialAnimation
        count={segments}
        size={size}
        segmentSize={7}
        segmentLength={48}
        inset={34}
        duration={6}
        stagger={0.107}
        dimColor="#1b3a6b"
        litColor="#22d3ee"
        className="-z-0 opacity-95"
      />

      <main className="relative z-10 flex flex-grow items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
        <div className="w-full max-w-md">
          {/*
            The panel is a sibling rendered above the ring (z-10), and the ring
            itself is pointer-events-none, so focus order and clicks always
            reach the form.
          */}
          <div
            className={
              variant === 'glass'
                ? 'mit-panel rounded-3xl p-7 sm:p-9'
                : 'mit-enter-scale rounded-3xl bg-white p-8 shadow-2xl ring-1 ring-slate-900/10 sm:p-10'
            }
          >
            <div className="mb-8 text-center">
              <div className="mb-3 inline-block">{glyph}</div>
              <h1 className="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">
                {title}
              </h1>
              {subtitle ? (
                <p className="mt-2 text-xs text-slate-500 sm:text-sm">{subtitle}</p>
              ) : null}
            </div>

            {children}
          </div>

          {footer ? (
            <div className="mit-enter-rise mt-5 text-center text-xs text-slate-300">{footer}</div>
          ) : null}
        </div>
      </main>
    </div>
  );
}