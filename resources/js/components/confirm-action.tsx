import { cloneElement, useRef, useState } from 'react';
import type { MouseEvent, ReactElement } from 'react';
import { ResponsiveDialogContent } from '@/components/patterns/responsive-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { __ } from '@/lib/i18n';

type SubmitButton = ReactElement<{
    onClick?: (event: MouseEvent<HTMLButtonElement>) => void;
}>;

/**
 * Asks before a form does something that cannot be undone. `children` is the
 * form's submit button: clicking it opens a dialog instead of submitting, and
 * the dialog's confirm button submits that same form. With `when` false the
 * button is left alone, so one control can ask for some choices only.
 */
export function ConfirmAction({
    title,
    description,
    confirmLabel,
    confirmVariant = 'destructive',
    when = true,
    onConfirm,
    children,
}: {
    title: string;
    description: string;
    confirmLabel: string;
    /** `default` for a step that asks first but removes nothing, such as a restore. */
    confirmVariant?: 'destructive' | 'default';
    when?: boolean;
    /** Runs instead of submitting the form, for a button that isn't in one. */
    onConfirm?: () => void;
    children: SubmitButton;
}) {
    const [open, setOpen] = useState(false);
    const button = useRef<HTMLButtonElement | null>(null);

    if (!when) {
        return children;
    }

    return (
        <>
            {cloneElement(children, {
                onClick: (event: MouseEvent<HTMLButtonElement>) => {
                    event.preventDefault();
                    button.current = event.currentTarget;
                    setOpen(true);
                },
            })}
            <Dialog open={open} onOpenChange={setOpen}>
                <ResponsiveDialogContent phone="foot">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline">{__('Cancel')}</Button>
                        </DialogClose>
                        <Button
                            variant={confirmVariant}
                            onClick={() => {
                                setOpen(false);

                                if (onConfirm !== undefined) {
                                    onConfirm();

                                    return;
                                }

                                button.current?.form?.requestSubmit(
                                    button.current,
                                );
                            }}
                        >
                            {confirmLabel}
                        </Button>
                    </DialogFooter>
                </ResponsiveDialogContent>
            </Dialog>
        </>
    );
}
