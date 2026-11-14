import { router } from '@inertiajs/react';
import { AlertTriangle, X, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

/** How long a result stays: 6 seconds, or 10 when it offers an Undo or a place to go, so the offer can be found. */
const DURATION = 6_000;
const DURATION_WITH_ACTION = 10_000;

type Action = { label: string; onClick: () => void };

/**
 * One toast: the foreground colour as its background and the card colour as
 * its text, the one shadow, the message, at most one action, and a Dismiss.
 * Colour is not how the kind is told: a warning or an error has an icon and
 * is spoken as one.
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
    const buttonClass =
        'focus-visible:outline-card rounded-md focus-visible:outline-2 focus-visible:outline-offset-1';

    return (
        <div
            data-test="flash-toast"
            className="bg-foreground text-card flex w-full items-center gap-2.5 rounded-lg py-2.5 pr-2 pl-3.5 text-[13px] shadow-(--shadow)"
        >
            {type === 'warning' && (
                <AlertTriangle aria-hidden="true" className="size-4 shrink-0" />
            )}
            {type === 'error' && (
                <XCircle aria-hidden="true" className="size-4 shrink-0" />
            )}
            <p className="min-w-0 flex-1">
                {type === 'warning' && (
                    <span className="sr-only">Warning: </span>
                )}
                {type === 'error' && <span className="sr-only">Error: </span>}
                {message}
            </p>
            {action && (
                <button
                    type="button"
                    className={`${buttonClass} min-h-7 cursor-pointer px-1.5 font-semibold underline underline-offset-2`}
                    onClick={() => {
                        toast.dismiss(id);
                        action.onClick();
                    }}
                >
                    {action.label}
                </button>
            )}
            <button
                type="button"
                data-test="flash-toast-dismiss"
                aria-label="Dismiss"
                className={`${buttonClass} grid size-7 shrink-0 cursor-pointer place-items-center`}
                onClick={() => toast.dismiss(id)}
            >
                <X aria-hidden="true" className="size-4" />
            </button>
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
