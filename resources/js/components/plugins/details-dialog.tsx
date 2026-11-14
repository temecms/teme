import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { __ } from '@/lib/i18n';
import type { PluginRowPage } from '@/types';

/**
 * Details and access (I-3353): what the person agreed to, what the plugin adds
 * to the admin, and what it listens for, each in words and never as a code.
 */
export function DetailsDialog({
    plugin,
    onClose,
}: {
    plugin: PluginRowPage | null;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={plugin !== null}
            onOpenChange={(next) => !next && onClose()}
        >
            {plugin !== null && (
                <DialogContent
                    data-test="details-dialog"
                    // The page gives focus back to the button that opened it.
                    onCloseAutoFocus={(event) => event.preventDefault()}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {plugin.label} {plugin.version}
                        </DialogTitle>
                        <DialogDescription className="font-mono text-xs">
                            {plugin.name}
                        </DialogDescription>
                    </DialogHeader>

                    {plugin.agreed.length > 0 && (
                        <section
                            aria-labelledby="details-access"
                            className="grid gap-2"
                        >
                            <h3
                                id="details-access"
                                className="text-[13px] font-semibold"
                            >
                                {__('Access you agreed to')}
                            </h3>
                            <ul className="list-disc pl-5 text-[13px]">
                                {plugin.agreed.map((line) => (
                                    <li key={line.words}>{line.words}</li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {plugin.adds.length > 0 && (
                        <section
                            aria-labelledby="details-adds"
                            className="grid gap-2"
                        >
                            <h3
                                id="details-adds"
                                className="text-[13px] font-semibold"
                            >
                                {__('What it adds to the admin')}
                            </h3>
                            <ul className="list-disc pl-5 text-[13px]">
                                {plugin.adds.map((label) => (
                                    <li key={label}>{label}</li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {plugin.listensFor.length > 0 && (
                        <section
                            aria-labelledby="details-hooks"
                            className="grid gap-2"
                        >
                            <h3
                                id="details-hooks"
                                className="text-[13px] font-semibold"
                            >
                                {__('It listens for')}
                            </h3>
                            <ul className="list-disc pl-5 text-[13px]">
                                {plugin.listensFor.map((words) => (
                                    <li key={words}>{words}</li>
                                ))}
                            </ul>
                        </section>
                    )}
                </DialogContent>
            )}
        </Dialog>
    );
}
