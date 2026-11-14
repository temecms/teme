import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { __ } from '@/lib/i18n';

/**
 * Asks before the editor is left while its latest changes are stored
 * nowhere: the app's own dialog, since a browser's confirm can be blocked
 * and answer No at once. Closing it stays.
 */
export function LeaveDialog({
    open,
    onStay,
    onLeave,
}: {
    open: boolean;
    onStay: () => void;
    onLeave: () => void;
}) {
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    onStay();
                }
            }}
        >
            <DialogContent
                data-test="leave-dialog"
                aria-describedby={undefined}
            >
                <DialogHeader>
                    <DialogTitle>
                        {__(
                            'Your latest changes aren’t saved. Leave this page anyway?',
                        )}
                    </DialogTitle>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onStay}>
                        {__('Stay')}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={onLeave}
                    >
                        {__('Leave without saving')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
