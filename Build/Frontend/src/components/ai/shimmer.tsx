import type { ComponentProps, ElementType } from 'react';
import { cn } from '@/lib/utils';

/**
 * Vendored from AI Elements, with `motion/react` removed.
 *
 * The original animates `background-position` through a motion component. The
 * same effect is one CSS keyframe (`@utility shimmer` in the stylesheet), and a
 * whole animation runtime is a lot of bundle for a moving gradient — in a
 * bundle that already has to carry React, Radix and a markdown pipeline.
 *
 * The CSS version also switches itself off under `prefers-reduced-motion`,
 * which is the behaviour this component is obliged to have and the one a
 * JavaScript animation has to be told about.
 */
export type ShimmerProps = ComponentProps<'span'> & {
  as?: ElementType;
};

export function Shimmer({ as: Component = 'span', className, children, ...props }: ShimmerProps) {
  return (
    <Component className={cn('shimmer inline-block', className)} {...props}>
      {children}
    </Component>
  );
}
