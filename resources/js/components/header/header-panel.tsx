import { router, usePage } from '@inertiajs/react';
import * as Dialog from '@radix-ui/react-dialog';
import type { ReactNode } from 'react';
import { useEffect, useRef } from 'react';

/**
 * A panel under a header button: a non-modal dialog drawn inside the header,
 * so it stays in the banner landmark. Escape closes it and returns focus to
 * its button; a click outside, or Tab past its last control, closes it; so
 * does a visit to another address.
 */
export function HeaderPanel({
    open,
    onOpenChange,
    title,
    testId,
    trigger,
    action,
    children,
    footer,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    testId: string;
    trigger: ReactNode;
    action?: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
}) {
    const { url } = usePage();
    // The address the panel was opened on; a visit elsewhere leaves it behind.
    const openedOn = useRef(url);

    useEffect(() => {
        if (open) {
            openedOn.current = url;
        }
    }, [open, url]);

    useEffect(
        () =>
            router.on('navigate', (event) => {
                if (event.detail.page.url !== openedOn.current) {
                    onOpenChange(false);
                }
            }),
        [onOpenChange],
    );

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange} modal={false}>
            <div className="relative">
                <Dialog.Trigger asChild>{trigger}</Dialog.Trigger>
                {/* No portal, so the open panel stays inside the banner landmark. */}
                <Dialog.Content
                    aria-describedby={undefined}
                    data-test={testId}
                    className="bg-popover text-popover-foreground border-border absolute top-full right-0 z-50 mt-1.5 max-h-[min(520px,calc(100vh-80px))] w-[min(380px,calc(100vw-16px))] overflow-y-auto rounded-lg border shadow-(--shadow) max-md:fixed max-md:inset-x-2 max-md:top-14 max-md:w-auto"
                >
                    <div className="bg-popover sticky top-0 flex items-center justify-between border-b px-3.5 py-2.5">
                        <Dialog.Title className="text-[13px] font-semibold">
                            {title}
                        </Dialog.Title>
                        {action}
                    </div>
                    {children}
                    {footer !== undefined && (
                        <div className="border-t px-3.5 py-2.5 text-[13px]">
                            {footer}
                        </div>
                    )}
                </Dialog.Content>
            </div>
        </Dialog.Root>
    );
}
