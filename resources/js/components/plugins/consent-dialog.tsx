import { CheckIcon } from 'lucide-react';
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
import { Notice } from '@/components/ui/notice';
import { PluginIcon } from '@/components/ui/plugin-icon';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import type {
    PluginAccessLine,
    PluginRowPage,
    PluginSettingHosts,
} from '@/types';

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

/** A NEW mark as a small info pill (design system 4.13), never an accent badge. */
function NewMark() {
    return (
        <StatusPill tone="info" flat className="text-caption h-5 px-2">
            {__('New')}
        </StatusPill>
    );
}

/** One consented line with an optional New mark. */
function AccessLine({
    line,
    hosts,
}: {
    line: PluginAccessLine;
    hosts?: PluginSettingHosts | null;
}) {
    return (
        <li className="flex items-baseline gap-2">
            <CheckIcon
                aria-hidden="true"
                className="text-ok-ink size-4 shrink-0"
            />
            {hosts !== null &&
            hosts !== undefined &&
            line.words === hosts.replaces ? (
                <span data-test="consent-setting-hosts">
                    {hosts.line}
                    <br />
                    {hosts.now}
                </span>
            ) : (
                <span>{line.words}</span>
            )}
            {line.new && <NewMark />}
        </li>
    );
}

/**
 * The consent dialog: trust in a warn banner, then It asks to / What it adds /
 * It listens for / It needs. AI task lines from the server (PluginWords) sit
 * under It asks to. The password ask stays on Teme's password screen when
 * needed; this dialog has no password box.
 */
export function ConsentDialog({
    plugin,
    settingHosts = null,
    onClose,
    onConfirm,
}: {
    plugin: PluginRowPage | null;
    settingHosts?: PluginSettingHosts | null;
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
                        <div className="flex items-center gap-4">
                            <PluginIcon
                                size={56}
                                name={plugin.label}
                                src={plugin.icon}
                            />
                            <div className="grid min-w-0 gap-1">
                                <DialogTitle>
                                    {plugin.state === 'waiting'
                                        ? __(
                                              ':name :version asks for more access',
                                              {
                                                  name: plugin.label,
                                                  version: plugin.version,
                                              },
                                          )
                                        : __('Enable :name :version?', {
                                              name: plugin.label,
                                              version: plugin.version,
                                          })}
                                </DialogTitle>
                                <DialogDescription className="sr-only">
                                    {__(
                                        'Review what :name asks for before you enable it.',
                                        { name: plugin.label },
                                    )}
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <Notice tone="warn" data-test="consent-trust">
                        <strong>{__('Plugins are trusted code.')}</strong>{' '}
                        {__(
                            ':name runs inside Teme with the same access to the server as Teme itself.',
                            { name: plugin.label },
                        )}{' '}
                        {__(
                            'Teme holds it to the list below wherever it hands out services, but it cannot stop a plugin that means harm. Enable plugins only from people you trust.',
                        )}
                    </Notice>

                    {plugin.agreedBefore && plugin.asksMore && (
                        <p className="text-small">
                            {__(
                                'You agreed to what version :agreed asks. Version :version adds the items marked New. Until you agree, :name stays disabled.',
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
                                className="text-small font-semibold"
                            >
                                {__('It asks to')}
                            </h3>
                            <ul
                                data-test="consent-asks"
                                className="text-small grid gap-3"
                            >
                                {plugin.asks.map((line) => (
                                    <AccessLine
                                        key={line.words}
                                        line={line}
                                        hosts={settingHosts}
                                    />
                                ))}
                            </ul>
                        </section>
                    )}

                    {plugin.adds.length > 0 && (
                        <section
                            aria-labelledby="consent-adds"
                            className="grid gap-2"
                        >
                            <h3
                                id="consent-adds"
                                className="text-small font-semibold"
                            >
                                {__('What it adds')}
                            </h3>
                            <ul
                                data-test="consent-adds"
                                className="text-small grid gap-2"
                            >
                                {plugin.adds.map((label) => (
                                    <li
                                        key={label}
                                        className="flex items-baseline gap-2"
                                    >
                                        <CheckIcon
                                            aria-hidden="true"
                                            className="text-ok-ink size-4 shrink-0"
                                        />
                                        <span>{label}</span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {plugin.listensFor.length > 0 && (
                        <section
                            aria-labelledby="consent-listens"
                            className="grid gap-2"
                        >
                            <h3
                                id="consent-listens"
                                className="text-small font-semibold"
                            >
                                {__('It listens for')}
                            </h3>
                            <ul
                                data-test="consent-listens"
                                className="text-small grid gap-2"
                            >
                                {plugin.listensFor.map((words) => (
                                    <li
                                        key={words}
                                        className="flex items-baseline gap-2"
                                    >
                                        <CheckIcon
                                            aria-hidden="true"
                                            className="text-ok-ink size-4 shrink-0"
                                        />
                                        <span>{words}</span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {plugin.requires.length > 0 && (
                        <section
                            aria-labelledby="consent-needs"
                            className="grid gap-2"
                        >
                            <h3
                                id="consent-needs"
                                className="text-small font-semibold"
                            >
                                {__('It needs')}
                            </h3>
                            <ul
                                data-test="consent-requires"
                                className="text-small grid gap-2"
                            >
                                {plugin.requires.map((line) => (
                                    <li
                                        key={line.words}
                                        className="flex flex-wrap items-baseline gap-2"
                                    >
                                        <CheckIcon
                                            aria-hidden="true"
                                            className="text-ok-ink size-4 shrink-0"
                                        />
                                        <span>{line.words}</span>
                                        {line.new && <NewMark />}
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
