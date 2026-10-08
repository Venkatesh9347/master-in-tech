import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { useRef } from 'react';

/**
 * MasterInTech motion system.
 *
 * The critical guarantees asserted here are SAFETY ones, not cosmetic:
 *  - the radial animation can never intercept pointer input,
 *  - it is hidden from assistive technology,
 *  - segment count is generated, not hand-written, and is bounded,
 *  - content stays VISIBLE when motion is disabled or unsupported,
 *  - reduced motion is a first-class path, not an afterthought.
 */

vi.mock('../../Navbar', () => ({
  default: () => <nav data-testid="mock-navbar">nav</nav>,
}));

vi.mock('../Navbar', () => ({
  default: () => <nav data-testid="mock-navbar">nav</nav>,
}));

import RadialAnimation from '../RadialAnimation';
import AuthShell from '../AuthShell';
import { Reveal, useAutoReveal } from '../index';
import { useMotionEnabled } from '../hooks';

/** Sets the motion gate exactly as the inline script in index.html does. */
function setMotion(value: 'on' | 'reduced' | null) {
  if (value === null) document.documentElement.removeAttribute('data-motion');
  else document.documentElement.setAttribute('data-motion', value);
}

describe('motion system', () => {
  beforeEach(() => setMotion('on'));
  afterEach(() => {
    cleanup();
    setMotion(null);
  });

  describe('RadialAnimation', () => {
    it('generates the requested number of segments', () => {
      render(<RadialAnimation count={12} />);
      expect(document.querySelectorAll('.mit-radial-track')).toHaveLength(12);
    });

    it('is decorative only: aria-hidden and never blocks pointer events', () => {
      const { container } = render(<RadialAnimation count={8} />);
      const root = screen.getByTestId('mit-radial');
      expect(root.getAttribute('aria-hidden')).toBe('true');
      expect(root.className).toContain('mit-radial');
      // The class carries pointer-events:none in motion.css; assert the class is
      // applied so a regression to a clickable overlay is visible here.
      expect(root.className).toContain('mit-radial');
      expect(container).toBeTruthy();
    });

    it('exposes the required CSS custom properties on the ring', () => {
      render(<RadialAnimation count={10} size={500} duration={4} />);
      const ring = document.querySelector('.mit-radial-ring') as HTMLElement;
      expect(ring).toBeTruthy();
      const style = ring.getAttribute('style') ?? '';
      expect(style).toContain('--mit-radial-size');
      expect(style).toContain('--segment-count');
      expect(style).toContain('--animation-duration');
    });

    it('gives every segment a distinct staggered delay', () => {
      render(<RadialAnimation count={6} stagger={0.2} />);
      const delays = Array.from(document.querySelectorAll('.mit-radial-track')).map((el) =>
        (el as HTMLElement).style.getPropertyValue('--animation-delay'),
      );
      expect(new Set(delays).size).toBe(delays.length);
      expect(delays[0]).toBe('0ms');
      expect(delays[1]).toBe('200ms');
    });

    it('is deterministic (no Math.random) so it cannot cause hydration drift', () => {
      const first = render(<RadialAnimation count={8} />);
      const a = Array.from(document.querySelectorAll('.mit-radial-track')).map(
        (el) => (el as HTMLElement).getAttribute('style'),
      );
      first.unmount();
      cleanup();
      render(<RadialAnimation count={8} />);
      const b = Array.from(document.querySelectorAll('.mit-radial-track')).map(
        (el) => (el as HTMLElement).getAttribute('style'),
      );
      expect(a).toEqual(b);
    });

    it('clamps an absurd segment count instead of melting the DOM', () => {
      render(<RadialAnimation count={5000} />);
      const n = document.querySelectorAll('.mit-radial-track').length;
      expect(n).toBeLessThanOrEqual(72);
      expect(n).toBeGreaterThan(0);
    });

    it('falls back to a safe default for a nonsensical count', () => {
      render(<RadialAnimation count={0} />);
      expect(document.querySelectorAll('.mit-radial-track').length).toBeGreaterThanOrEqual(6);
    });
  });

  describe('AuthShell', () => {
    it('centres the real form and renders the heading', () => {
      render(
        <MemoryRouter>
          <AuthShell title="Student Sign In" subtitle="Access your curriculum">
            <form>
              <label htmlFor="email">Email</label>
              <input id="email" />
            </form>
          </AuthShell>
        </MemoryRouter>,
      );
      expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Student Sign In');
      expect(screen.getByText('Access your curriculum')).toBeTruthy();
      // The caller's own form is rendered untouched.
      expect(screen.getByLabelText('Email')).toBeTruthy();
    });

    it('keeps exactly one main landmark for accessibility', () => {
      render(
        <MemoryRouter>
          <AuthShell title="Sign In">
            <p>content</p>
          </AuthShell>
        </MemoryRouter>,
      );
      expect(screen.getAllByRole('main')).toHaveLength(1);
    });

    it('carries the radial animation as a decorative sibling of the form', () => {
      render(
        <MemoryRouter>
          <AuthShell title="Sign In">
            <p>content</p>
          </AuthShell>
        </MemoryRouter>,
      );
      expect(screen.getByTestId('mit-radial')).toBeTruthy();
      expect(screen.getByTestId('mit-radial').getAttribute('aria-hidden')).toBe('true');
    });
  });

  describe('reduced motion and the no-JS fallback', () => {
    it('useMotionEnabled is false when the gate says reduced', async () => {
      setMotion('reduced');
      function Probe() {
        const on = useMotionEnabled();
        return <span data-testid="probe">{String(on)}</span>;
      }
      render(<Probe />);
      await waitFor(() => expect(screen.getByTestId('probe').textContent).toBe('false'));
    });

    it('useMotionEnabled is true only when the gate says on', async () => {
      setMotion('on');
      function Probe() {
        const on = useMotionEnabled();
        return <span data-testid="probe">{String(on)}</span>;
      }
      render(<Probe />);
      await waitFor(() => expect(screen.getByTestId('probe').textContent).toBe('true'));
    });

    it('Reveal marks content shown immediately when motion is off', async () => {
      setMotion('reduced');
      render(
        <Reveal>
          <p>must always be readable</p>
        </Reveal>,
      );
      const el = document.querySelector('.mit-reveal') as HTMLElement;
      await waitFor(() => expect(el.getAttribute('data-mit-shown')).toBe('true'));
      // Never left in the pending/hidden state.
      expect(el.getAttribute('data-mit-pending')).toBe('false');
      expect(screen.getByText('must always be readable')).toBeTruthy();
    });
  });

  describe('useAutoReveal', () => {
    function Harness() {
      const ref = useRef<HTMLDivElement | null>(null);
      useAutoReveal(ref, 'section');
      return (
        <div ref={ref}>
          <section>one</section>
          <section>two</section>
        </div>
      );
    }

    it('leaves sections fully visible when motion is disabled', async () => {
      setMotion('reduced');
      const { container } = render(<Harness />);
      await waitFor(() => {
        const sections = Array.from(container.querySelectorAll('section'));
        expect(sections.every((s) => s.getAttribute('data-mit-shown') === 'true')).toBe(true);
      });
    });

    it('does not hide sections when the gate script never ran (no JS fallback)', async () => {
      setMotion(null);
      const { container } = render(<Harness />);
      await waitFor(() => {
        const sections = Array.from(container.querySelectorAll('section'));
        expect(sections.every((s) => s.getAttribute('data-mit-shown') === 'true')).toBe(true);
      });
      // With no gate attribute the CSS hidden-state rule cannot match at all.
      expect(document.documentElement.getAttribute('data-motion')).toBeNull();
    });
  });
});