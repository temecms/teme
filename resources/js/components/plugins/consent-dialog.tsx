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
 * The words the dialog's button carries: the plain Enable while an earlier
 * agreement still covers what the plugin asks, and Agree and enable whenever
 * a person agrees to access, at the first enable and for an update that asks
 * for more.
 */
export function confirmLabel(plugin: PluginRowPage): string {
    return plugin.agreedBefore && !plugin.asksMore
        ? __('Enable :name', { name: plugin.label })
        : __('Agree and enable :version', { version: plugin.version });
}

/**
 * The consent dialog (I-3353): what the plugin asks to do, in words, with NEW
 * beside what an update adds. It has no password box: when the password was
 * not confirmed lately the server sends the person to Teme's password screen,
 * and the page then enables the plugin itself.
 */
export function ConsentDialog({
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
                    data-test="consent-dialog"
                    // The page gives focus back to the button that opened it.
                    onCloseAutoFocus={(event) => event.preventDefault()}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {plugin.state === 'waiting'
                                ? __(':name :version asks for more access', {
                                      name: plugin.label,
                                      version: plugin.version,
                                  })
                                : __('Enable :name :version?', {
                                      name: plugin.label,
                                      version: plugin.version,
                                  })}
                        </DialogTitle>
                        <DialogDescription>
                            <strong>{__('Plugins are trusted code.')}</strong>{' '}
                            {__(
                                ':name runs inside Teme with the same access to the server as Teme itself.',
                                { name: plugin.label },
                            )}{' '}
                            {__(
                                'Teme holds it to the list below wherever it hands out services, but it cannot stop a plugin that means harm. Enable plugins only from people you trust.',
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    {plugin.agreedBefore && plugin.asksMore && (
                        <p className="text-[13px]">
                            {__(
                                'You agreed to what version :agreed asks. Version :version adds the items marked NEW. Until you agree, :name stays disabled.',
                                {
                                    agreed: plugin.agreedVersion ?? '',
                                    version: plugin.version,
                                    name: plugin.label,
                                },
                            )}
                        </p>
                    )}

                    {plugin.asks.length > 0 && (
                        <section
                            aria-labelledby="consent-asks"
                            className="grid gap-2"
                        >
                            <h3
                                id="consent-asks"
                                className="text-[13px] font-semibold"
                            >
                                {__('It asks to')}
                            </h3>
                            <ul
                                data-test="consent-asks"
                                className="grid gap-1.5 text-[13px]"
                            >
                                {plugin.asks.map((line) => (
                                    <li
                                        key={line.words}
                                        className="flex flex-wrap items-baseline gap-2"
                                    >
                                        <span>{line.words}</span>
                                        {line.new && (
                                            <span className="bg-accent text-on-accent rounded-sm px-1.5 text-[11px] leading-4 font-semibold">
                                                {__('NEW')}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                {__('Cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            data-test="consent-confirm"
                            onClick={() => onConfirm(plugin)}
                        >
                            {confirmLabel(plugin)}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            )}
        </Dialog>
    );
}
