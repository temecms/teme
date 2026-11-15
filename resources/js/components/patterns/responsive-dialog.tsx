import type { ComponentProps } from 'react';
import { DialogContent } from '@/components/ui/dialog';
import { ScrollArea } from '@/components/ui/scroll-area';
import { cn } from '@/lib/utils';

/** How a dialog sits on a phone: the whole screen, or a sheet from the foot. */
type PhoneShape = 'full' | 'foot';

/**
 * Below 640px: the whole screen, or a sheet from the foot of it for a
 * confirmation (docs/proposals/phone-overlays.md).
 */
const PHONE: Record<PhoneShape, string> = {
    full: 'max-sm:inset-0 max-sm:max-h-none max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-s-none max-sm:rounded-e-none',
    foot: 'max-sm:inset-x-0 max-sm:top-auto max-sm:bottom-0 max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-s-none max-sm:rounded-e-none max-sm:rounded-t-xl max-sm:motion-safe:data-[state=open]:slide-in-from-bottom',
};

/** The frame: one scrolling body between a head and a footer that stay in view. */
const FRAME = 'flex max-h-(--dialog-xl-h) flex-col gap-0 p-0';

/** The body, whose own head and footer stick to its top and its foot. */
const BODY = [
    'grid flex-1 content-start gap-4 px-6 pt-4 pb-6 max-sm:px-4',
    '**:data-[slot=dialog-header]:sticky **:data-[slot=dialog-header]:-top-4 **:data-[slot=dialog-header]:-mt-4 **:data-[slot=dialog-header]:bg-surface **:data-[slot=dialog-header]:pt-4 **:data-[slot=dialog-header]:pb-2',
    '**:data-[slot=dialog-footer]:sticky **:data-[slot=dialog-footer]:-bottom-6 **:data-[slot=dialog-footer]:-mb-6 **:data-[slot=dialog-footer]:bg-surface **:data-[slot=dialog-footer]:pt-3 **:data-[slot=dialog-footer]:pb-6',
].join(' ');

type DialogContentProps = ComponentProps<typeof DialogContent>;

/**
 * A dialog that fits a phone (docs/architecture/design-system.md, section
 * 4.19): used in place of `DialogContent`, with the same props. Its body
 * scrolls inside at every width while its head and actions stay in view;
 * below 640px it fills the screen, or with `phone="foot"`, for a question
 * answered by its two buttons, it opens from the screen's foot.
 */
export function ResponsiveDialogContent({
    phone = 'full',
    className,
    children,
    ...props
}: Omit<DialogContentProps, 'size'> & {
    /** The large dialog is already full screen on a phone, so it is not taken here. */
    size?: Exclude<DialogContentProps['size'], 'xl'>;
    phone?: PhoneShape;
}) {
    return (
        <DialogContent
            data-phone={phone}
            className={cn(FRAME, PHONE[phone], className)}
            {...props}
        >
            <ScrollArea data-slot="responsive-dialog-body" className={BODY}>
                {children}
            </ScrollArea>
        </DialogContent>
    );
}
