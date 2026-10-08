/**
 * MasterInTech — motion hooks.
 *
 * Both hooks live here rather than in primitives.tsx because React Fast Refresh
 * cannot co-locate hook exports with component exports.
 */

import { useEffect, useState, type RefObject } from 'react';

/**
 * True when entrance animation should play.
 *
 * False when:
 *  - the user prefers reduced motion (motion.css also neutralises the CSS side),
 *  - we are server-rendering / pre-hydration, so nothing animates before the
 *    client has settled,
 *  - the inline motion-gate script did not run.
 *
 * In every one of those cases the fallback is fully visible content, never a
 * hidden element waiting for an animation that will not come.
 */
export function useMotionEnabled(): boolean {
  const [enabled, setEnabled] = useState(false);

  useEffect(() => {
    if (typeof window === 'undefined') return;

    const root = document.documentElement;
    const apply = () => setEnabled(root.getAttribute('data-motion') === 'on');

    apply();

    // Follow live OS-level changes without reloading.
    let mq: MediaQueryList | null = null;
    try {
      mq = window.matchMedia('(prefers-reduced-motion: reduce)');
      mq.addEventListener('change', apply);
    } catch {
      /* matchMedia unavailable: static fallback already in effect */
    }

    return () => {
      mq?.removeEventListener('change', apply);
    };
  }, []);

  return enabled;
}

/**
 * Automatically reveals every element matching `selector` inside a container.
 *
 * This exists so large CMS-driven pages (Home, About, CorporatePartner…) get
 * scroll reveals without wrapping each section in a component by hand — those
 * sections live inside feature-flag conditionals, so manual wrapping is both
 * fragile and noisy.
 *
 * One shared IntersectionObserver for the whole container, unobserving each
 * element once shown, so scrolling costs nothing afterwards.
 *
 * Safety: elements start VISIBLE. They are marked pending only immediately
 * before observing, and if motion is off or IntersectionObserver is missing
 * they are marked shown immediately. A failure here can never hide content.
 */
export function useAutoReveal<T extends HTMLElement>(
  containerRef: RefObject<T | null>,
  selector = 'section',
): void {
  const enabled = useMotionEnabled();

  useEffect(() => {
    const root = containerRef.current;
    if (!root) return;

    const nodes = Array.from(root.querySelectorAll<HTMLElement>(selector));

    if (!enabled || typeof IntersectionObserver === 'undefined') {
      nodes.forEach((node) => node.setAttribute('data-mit-shown', 'true'));
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          const target = entry.target as HTMLElement;
          if (entry.isIntersecting) {
            target.setAttribute('data-mit-shown', 'true');
            target.removeAttribute('data-mit-pending');
            observer.unobserve(target);
          }
        });
        // Stop observing entirely once everything has been revealed.
        if (nodes.every((n) => n.getAttribute('data-mit-shown') === 'true')) {
          observer.disconnect();
        }
      },
      { threshold: 0.08, rootMargin: '0px 0px -6% 0px' },
    );

    nodes.forEach((node, index) => {
      node.classList.add('mit-reveal');
      node.setAttribute('data-mit-pending', 'true');
      node.style.setProperty('--mit-i', String(index % 6));
      observer.observe(node);
    });

    return () => observer.disconnect();
  }, [containerRef, selector, enabled]);
}