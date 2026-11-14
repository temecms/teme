import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * Asks before something that cannot be undone is done to several items,
 * naming each: the title says how many, and every name is listed under the
 * sentence, in a list that scrolls. With one item, the title names it and
 * no list is drawn.
 */
export function ConfirmItems({
    open,
    onOpenChange,
    title,
    description,
    items,
    listLabel,
    confirmLabel,
    processing = false,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    /** The items' names, in the order the list shows them. */
    items: string[];
    /** Names the list for screen readers, as in "Posts to delete". */
    listLabel: string;
    confirmLabel: string;
    processing?: boolean;
    onConfirm: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent data-test="confirm-items">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                {items.length > 1 && (
                    <ul
                        aria-label={listLabel}
                        data-test="confirm-items-list"
                        tabIndex={0}
                        className="border-border max-h-60 list-disc overflow-y-auto rounded border py-2 pr-3 pl-8 text-[13px]"
                    >
                        {items.map((name, at) => (
                            <li key={`${at}-${name}`}>{name}</li>
                        ))}
                    </ul>
                )}
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={processing}
                        onClick={onConfirm}
                    >
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
