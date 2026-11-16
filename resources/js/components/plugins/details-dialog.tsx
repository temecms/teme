import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { originLabel } from '@/components/extensions/words';
import { PluginIcon } from '@/components/ui/plugin-icon';
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
                        <div className="flex items-center gap-4">
                            <PluginIcon size={56} name={plugin.label} />
                            <div className="grid min-w-0 gap-1">
                                <DialogTitle>
                                    {plugin.label} {plugin.version}
                                </DialogTitle>
                                <DialogDescription className="text-caption font-mono">
                                    {plugin.name}
                                </DialogDescription>
                                <p className="text-caption text-ink-2">
                                    {originLabel(plugin.origin, plugin.source)}
                                </p>
                            </div>
                        </div>
                    </DialogHeader>

                    {plugin.agreed.length > 0 && (
                        <section
                            aria-labelledby="details-access"
                            className="grid gap-2"
                        >
                            <h3
                                id="details-access"
                                className="text-small font-semibold"
                            >
                                {__('Access you agreed to')}
                            </h3>
                            <ul className="text-small list-disc pl-5">
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
                                className="text-small font-semibold"
                            >
                                {__('What it adds to the admin')}
                            </h3>
                            <ul className="text-small list-disc pl-5">
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
                                className="text-small font-semibold"
                            >
                                {__('It listens for')}
                            </h3>
                            <ul className="text-small list-disc pl-5">
                                {plugin.listensFor.map((words) => (
                                    <li key={words}>{words}</li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {plugin.requires.length > 0 && (
                        <ul
                            data-test="details-requires"
                            className="text-small grid gap-2"
                        >
                            {plugin.requires.map((line) => (
                                <li key={line.words}>{line.words}</li>
                            ))}
                        </ul>
                    )}

                    {plugin.requiredBy.length > 0 && (
                        <section
                            aria-labelledby="details-required-by"
                            className="grid gap-2"
                        >
                            <h3
                                id="details-required-by"
                                className="text-small font-semibold"
                            >
                                {__('Needed by')}
                            </h3>
                            <ul
                                data-test="details-required-by"
                                className="text-small list-disc pl-5"
                            >
                                {plugin.requiredBy.map((label) => (
                                    <li key={label}>{label}</li>
                                ))}
                            </ul>
                        </section>
                    )}
                </DialogContent>
            )}
        </Dialog>
    );
}
