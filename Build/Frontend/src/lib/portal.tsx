import { createContext, useContext, type ReactNode } from 'react';

/**
 * Where Radix is allowed to put a floating layer.
 *
 * Every Radix portal defaults to `document.body`, which is OUTSIDE the shadow
 * root — so a dialog, a dropdown or a tooltip would render into the backend's
 * document, where this bundle's stylesheet does not reach. The result is not a
 * subtle visual regression: it is an unstyled menu floating over the backend.
 *
 * So the element hands every portal an explicit container inside its own shadow
 * root, and this context is how that node reaches the components that need it.
 * `null` is a legitimate value — it means "no container was provided", which is
 * what Radix already does by default and what a unit test rendering a component
 * on its own gets.
 */
const PortalContainerContext = createContext<HTMLElement | null>(null);

export function PortalContainerProvider({
  container,
  children,
}: {
  container: HTMLElement | null;
  children: ReactNode;
}) {
  return (
    <PortalContainerContext.Provider value={container}>{children}</PortalContainerContext.Provider>
  );
}

export function usePortalContainer(): HTMLElement | undefined {
  return useContext(PortalContainerContext) ?? undefined;
}
