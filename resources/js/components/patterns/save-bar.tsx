import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useLeaveGuard } from '@/hooks/use-leave-guard';
import { __ } from '@/lib/i18n';

/**
 * The one save bar (docs/architecture/design-system.md, section 4.12):
 * hidden until something changes, then a bar at the foot of the form with
 * Discard and Save, and a question before the page is left with the changes
 * unsaved. It is the last child of its form, so Save submits it.
 */
export function SaveBar({
    dirty,
    processing,
    onDiscard,
    saveLabel,
    what,
    testId,
    'aria-describedby': describedBy,
}: {
    dirty: boolean;
    processing: boolean;
    onDiscard: () => void;
    saveLabel?: string;
    /** What is unsaved, for the question before leaving: "Your changes to {what}…". */
    what: string;
    /** The Save button's `data-test`, for a page whose tests already name it. */
    testId?: string;
    /** What Save is described by, such as the form's own error line. */
    'aria-describedby'?: string;
}) {
    const guard = useLeaveGuard(dirty);

    return (
        <>
            <div
                role="region"
                aria-label={__('Unsaved changes')}
                data-test="save-bar"
                hidden={!dirty}
                className="bg-ink text-page shadow-float motion-safe:animate-in motion-safe:fade-in-0 motion-safe:slide-in-from-bottom-2 sticky bottom-4 z-(--z-sticky) flex flex-wrap items-center gap-3 rounded-lg py-3 pr-3 pl-5 duration-(--dur-2) ease-(--ease) max-sm:fixed max-sm:inset-x-0 max-sm:bottom-0 max-sm:rounded-none"
            >
                <p className="text-body min-w-0 flex-1 font-medium">
                    {__('You have unsaved changes')}
                </p>
                <Button
                    type="button"
                    variant="ghost"
                    data-test="save-bar-discard"
                    className="text-page hover:bg-page/15 hover:text-page active:bg-page/15 active:text-page focus-visible:outline-page"
                    onClick={onDiscard}
                    disabled={processing}
                >
                    {__('Discard')}
                </Button>
                <Button
                    type="submit"
                    loading={processing}
                    data-test={testId ?? 'save-bar-save'}
                    aria-describedby={describedBy}
                    className="focus-visible:outline-page"
                >
                    {saveLabel ?? __('Save')}
                </Button>
            </div>
            <Dialog
                open={guard.asking}
                onOpenChange={(open) => {
                    if (!open) {
                        guard.stay();
                    }
                }}
            >
                <DialogContent size="sm" data-test="leave-guard">
                    <DialogHeader>
                        <DialogTitle>{__('Leave without saving?')}</DialogTitle>
                        <DialogDescription>
                            {__(
                                'Your changes to :what are not saved, and leaving discards them.',
                                { what },
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="neutral"
                            data-test="leave-guard-stay"
                            onClick={guard.stay}
                        >
                            {__('Keep editing')}
                        </Button>
                        <Button
                            type="button"
                            variant="danger"
                            data-test="leave-guard-leave"
                            onClick={guard.leave}
                        >
                            {__('Discard and leave')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
