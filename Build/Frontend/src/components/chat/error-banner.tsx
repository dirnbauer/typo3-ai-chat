import { AlertTriangleIcon, XIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';

/**
 * What went wrong, once, where the user is looking.
 *
 * `role="alert"` so a screen reader hears it without having to go looking: a
 * turn that fails silently is a turn the user waits out.
 */
export function ErrorBanner({ message, onDismiss }: { message: string; onDismiss?: () => void }) {
  return (
    <div
      className="flex items-start gap-2 border-b border-destructive/40 bg-destructive/10 px-3 py-2 text-destructive"
      role="alert"
    >
      <AlertTriangleIcon className="mt-px size-3.5 shrink-0" aria-hidden="true" />
      <p className="min-w-0 flex-1">{message}</p>
      {onDismiss === undefined ? null : (
        <Button aria-label="Dismiss" className="-my-1" onClick={onDismiss} size="icon-sm" variant="ghost">
          <XIcon aria-hidden="true" />
        </Button>
      )}
    </div>
  );
}
