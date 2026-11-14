import { router } from '@inertiajs/react';
import { OctagonAlert, TriangleAlert, X } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import type { FlashToast } from '@/types/ui';

/** How long a result stays: 6 seconds, or 10 when it offers an Undo or a place to go, so the offer can be found. */
const DURATION = 6_000;
const DURATION_WITH_ACTION = 10_000;

type Action = { label: string; onClick: () => void };

/** A button in the toast's inverse colours. */
const inverse =
    'text-page hover:bg-page/15 hover:text-page active:bg-page/15 active:text-page focus-visible:outline-page';

/**
 * One toast (docs/architecture/design-system.md, section 4.21): the ink as
 * its ground and the page colour as its words, the message, at most one
 * action, and a Dismiss. Colour is not how the kind is told: a warning or a
 * failure leads with its icon and is spoken as one.
 */
function FlashToastView({
    id,
    type,
    message,
    action,
}: {
    id: number | string;
    type: FlashToast['type'];
    message: string;
    action?: Action;
}) {
    return (
        <div
            data-test="flash-toast"
            className="bg-ink text-page shadow-float text-body flex min-h-11 w-full items-center gap-3 rounded-lg py-2 pr-2 pl-4 font-medium"
        >
            {type === 'warning' && (
                <TriangleAlert aria-hidden="true" className="size-5 shrink-0" />
            )}
            {type === 'error' && (
                <OctagonAlert aria-hidden="true" className="size-5 shrink-0" />
            )}
            <p className="min-w-0 flex-1">
                {type === 'warning' && (
                    <span className="sr-only">Warning: </span>
                )}
                {type === 'error' && <span className="sr-only">Error: </span>}
                {message}
            </p>
            {action && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className={inverse}
                    onClick={() => {
                        toast.dismiss(id);
                        action.onClick();
                    }}
                >
                    {action.label}
                </Button>
            )}
            <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                data-test="flash-toast-dismiss"
                aria-label="Dismiss"
                className={inverse}
                onClick={() => toast.dismiss(id)}
            >
                <X aria-hidden="true" />
            </Button>
        </div>
    );
}

/**
 * Shows a toast. The server's flashes come through showFlashToast(); a
 * screen that makes a result of its own, such as one the browser undoes,
 * calls this. Sonner keeps the toast while the pointer is over it.
 */
export function showToast({
    type,
    message,
    action,
}: {
    type: FlashToast['type'];
    message: string;
    action?: Action;
}): void {
    toast.custom(
        (id) => (
            <FlashToastView
                id={id}
                type={type}
                message={message}
                action={action}
            />
        ),
        { duration: action ? DURATION_WITH_ACTION : DURATION },
    );
}

/**
 * Shows a result the server flashed. An Undo posts the reverse moves the
 * server named; a link goes to the place it offers next.
 */
export function showFlashToast(data: FlashToast): void {
    const undo = data.undo;
    const link = data.link;
    const action: Action | undefined = undo
        ? {
              label: 'Undo',
              onClick: () =>
                  router.post(
                      undo.href,
                      undo.data as Parameters<typeof router.post>[1],
                      { preserveScroll: true },
                  ),
          }
        : link
          ? { label: link.label, onClick: () => router.visit(link.href) }
          : undefined;

    showToast({ type: data.type, message: data.message, action });
}
