/**
 * MasterInTech — motion system barrel.
 *
 * One import site for the whole animation language:
 *   import { Reveal, AnimatedCard } from '../components/motion';
 */
export { default as RadialAnimation } from './RadialAnimation';
export type { RadialAnimationProps } from './RadialAnimation';

export {
  AnimatedBackground,
  AnimatedButton,
  AnimatedCard,
  AnimatedInput,
  AnimatedPage,
  Entrance,
  GlowBorder,
  RadialLoader,
  Reveal,
  Shimmer,
  Skeleton,
  StaggerContainer,
  StaggerItem,
} from './primitives';

export { useAutoReveal, useMotionEnabled } from './hooks';