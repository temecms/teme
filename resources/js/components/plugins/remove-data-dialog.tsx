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
import { ScrollArea } from '@/components/ui/scroll-area';
import { __ } from '@/lib/i18n';
import type { PluginRowPage } from '@/types';

/**
 * Confirms removing a plugin’s recorded tables (I-3442): lists each table
 * with its approximate rows, then the danger button. The password is asked
 * on Teme’s password screen, as Enable is.
 */
export function RemoveDataDialog({
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
                    data-test="remove-data-dialog"
                    onCloseAutoFocus={(event) => event.preventDefault()}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {__('Remove :name’s data?', {
                                name: plugin.label,
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {__(
                                'These tables and everything in them are deleted. This can’t be undone.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <ScrollArea
                        label={__('Tables to remove')}
                        className="max-h-60"
                    >
                        <ul
                            data-test="remove-data-tables"
                            className="text-small list-disc space-y-1 py-1 pr-3 pl-8"
                        >
                            {plugin.tables.map((table) => (
                                <li key={table.name}>
                                    {table.rows < 0
                                        ? table.name
                                        : __(':table (about :count rows)', {
                                              table: table.name,
                                              count: table.rows,
                                          })}
                                </li>
                            ))}
                        </ul>
                    </ScrollArea>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                {__('Cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            variant="destructive"
                            data-test="remove-data-confirm"
                            onClick={() => onConfirm(plugin)}
                        >
                            {__('Remove data')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            )}
        </Dialog>
    );
}
