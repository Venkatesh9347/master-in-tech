/**
 * MasterInTech — Radial segmented animation.
 *
 * The signature visual: a circle of independent segments surrounding focal
 * content, each with its own staggered delay so the ring pulses as a ripple.
 *
 * Segments are GENERATED from `count` with a single map — never hand-written
 * JSX. Per-segment variation is driven entirely by CSS custom properties
 * (--segment-count, --segment-index, --animation-duration, --animation-delay,
 *  --segment-size, --segment-color), so tuning the look needs no code change.
 *
 * Guaranteed non-interference:
 * - `pointer-events: none` — can never block a click or a form field
 * - decorative only (`aria-hidden`), so assistive tech ignores it
 * - no continuous animation under prefers-reduced-motion (see motion.css)
 * - scales down on small screens via CSS, not JS
 */

import { useMemo } from 'react';

export interface RadialAnimationProps {
  /** Number of segments. Capped so a mis-configured prop cannot melt the DOM. */
  count?: number;
  /** Diameter of the ring in px. */
  size?: number;
  /** Thickness and length of each segment. */
  segmentSize?: number;
  /** Seconds for one full pulse cycle. */
  duration?: number;
  /** Seconds between the start of each segment's pulse. */
  stagger?: number;
  /** Radial band position: distance from the ring's outer edge, in px. */
  inset?: number;
  /** Length of each segment along the radius, in px. */
  segmentLength?: number;
  /** Dim (unlit) segment colour. */
  dimColor?: string;
  /** Bright (lit) segment colour for the travelling wave. */
  litColor?: string;
  /** Show the faint structural ring behind the segments. */
  showHalo?: boolean;
  className?: string;
}

const MAX_SEGMENTS = 72;
const DEFAULT_SEGMENTS = 56;

export default function RadialAnimation({
  count = DEFAULT_SEGMENTS,
  size = 620,
  segmentSize = 7,
  duration = 6,
  stagger = 0.107,
  inset = 30,
  segmentLength = 46,
  dimColor = '#1b3a6b',
  litColor = '#22d3ee',
  showHalo = true,
  className = '',
}: RadialAnimationProps) {
  const segments = useMemo(() => {
    const total = Math.max(6, Math.min(Math.floor(count) || DEFAULT_SEGMENTS, MAX_SEGMENTS));
    return Array.from({ length: total }, (_, index) => ({
      index,
      delay: Math.round(index * stagger * 1000),
    }));
  }, [count, stagger]);

  return (
    <div className={`mit-radial ${className}`} aria-hidden="true" data-testid="mit-radial">
      <div
        className="mit-radial-ring"
        style={
          {
            '--mit-radial-size': `${size}px`,
            '--segment-count': segments.length,
            '--segment-size': `${segmentSize}px`,
            '--segment-length': `${segmentLength}px`,
            '--segment-inset': `${inset}px`,
            '--segment-dim': dimColor,
            '--segment-lit': litColor,
            '--animation-duration': `${duration}s`,
          } as React.CSSProperties
        }
      >
        {showHalo ? <div className="mit-radial-halo" /> : null}

        {segments.map((segment) => (
          <div
            key={segment.index}
            className="mit-radial-track"
            style={
              {
                '--segment-index': segment.index,
                '--segment-delay': `${segment.delay}ms`,
                '--animation-delay': `${segment.delay}ms`,
                // Rotation only. Applying a scale here would scale the whole
                // track about the ring centre and push segments onto different
                // radii, which destroys the even circle.
                transform: `rotate(${(segment.index * 360) / segments.length}deg)`,
              } as React.CSSProperties
            }
          >
            <div className="mit-radial-segment" />
          </div>
        ))}
      </div>
    </div>
  );
}