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
import { __ } from '@/lib/i18n';
import type { PluginRowPage } from '@/types';

/**
 * Confirms forgetting a disabled plugin whose package is gone: its record
 * is deleted so another package can use its code. The password is asked on
 * Teme’s password screen, as Remove its data is.
 */
export function ForgetDialog({
    plugin,
    onClose,
    onConfirm,
}: {
    plugin: PluginRowPage | null;
    onClose: () => void;
    onConfirm: (plugin: PluginRowPage) => void;
}) {
    return (
        <Dialog
            open={plugin !== null}
            onOpenChange={(next) => !next && onClose()}
        >
            {plugin !== null && (
                <DialogContent
                    data-test="forget-dialog"
                    onCloseAutoFocus={(event) => event.preventDefault()}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {__('Forget :name?', {
                                name: plugin.label,
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {__(
                                'Its record is removed so another package can use its code. Its settings and consent are deleted. This can’t be undone.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                {__('Cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            variant="destructive"
                            data-test="forget-confirm"
                            onClick={() => onConfirm(plugin)}
                        >
                            {__('Forget')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            )}
        </Dialog>
    );
}
