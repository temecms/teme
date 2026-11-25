import { usePage } from '@inertiajs/react';
import type { ReactNodeViewProps } from '@tiptap/react';
import { ChevronRight, CodeXml } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { IconTile } from '@/components/ui/icon-tile';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import type { ContentEditPage } from '@/types';
import { CardField, CardFrame } from './card-frame';
import type { CardSettingsProps } from './card-environment';

/** Whether this person may read and change a card's code, and whether the host has switched site code off. */
function useCodeRights(): { manages: boolean; locked: boolean } {
    const props = usePage<ContentEditPage>().props;

    return {
        manages: props.canManageSiteCode === true,
        locked: props.siteCodeLocked === true,
    };
}

/** The hosts a card keeps, one a line, as the person typed them. */
function hostsOf(value: unknown): string[] {
    return Array.isArray(value)
        ? value.filter((host): host is string => typeof host === 'string')
        : [];
}

/**
 * Custom HTML: code of its own, such as one ad unit, with the hosts it
 * connects to. Only someone who may manage site code reads or writes it,
 * here in place; anyone else sees the card, and may move or remove it, but
 * never its code, which the server hides from them and keeps as it was.
 */
export function SiteCodeCard({
    node,
    selected,
    updateAttributes,
    deleteNode,
}: ReactNodeViewProps) {
    const { manages, locked } = useCodeRights();
    const code = typeof node.attrs.code === 'string' ? node.attrs.code : '';

    let words: string;

    if (locked) {
        words = __(
            'The host has switched site code off, so readers see nothing here.',
        );
    } else if (!manages) {
        words = __('Only administrators can read or change this code.');
    } else if (code === '') {
        words = __(
            'Paste the code below. Readers see nothing here until then.',
        );
    } else {
        words = __('Readers see it here, drawn exactly as it was pasted.');
    }

    return (
        <CardFrame
            label={__('Custom HTML')}
            selected={selected}
            onRemove={deleteNode}
            defaultOpen={false}
            settings={
                <SiteCodeSettings
                    attrs={node.attrs}
                    update={updateAttributes}
                />
            }
        >
            <div className="grid min-w-0 gap-3">
                <div className="flex min-w-0 items-start gap-3">
                    <IconTile icon={CodeXml} />
                    <p className="text-ink-2 text-small">{words}</p>
                </div>
                {manages && !locked && (
                    <CardField
                        label={__('Code')}
                        hint={__(
                            'Paste the ad unit or embed code exactly as the service gives it.',
                        )}
                    >
                        {(props) => (
                            <Textarea
                                {...props}
                                rows={4}
                                spellCheck={false}
                                value={code}
                                onChange={(event) =>
                                    updateAttributes({
                                        code: event.target.value,
                                    })
                                }
                                className="text-small font-mono"
                            />
                        )}
                    </CardField>
                )}
            </div>
        </CardFrame>
    );
}

/**
 * A Custom HTML card's settings, for someone who may manage site code: the
 * hosts its code connects to, folded away. Anyone else is told who
 * may change it.
 */
export function SiteCodeSettings({
    attrs,
    update: updateAttributes,
}: CardSettingsProps) {
    const { manages, locked } = useCodeRights();
    // The lines as typed, so an empty line being written survives; the card keeps the hosts.
    const [hosts, setHosts] = useState(() => hostsOf(attrs.origins).join('\n'));

    if (!manages || locked) {
        return (
            <p className="text-ink-2 text-small">
                {locked
                    ? __(
                          'The host has switched site code off, so this code can’t be changed. You can still remove the card.',
                      )
                    : __(
                          'Only administrators can read or change this code. You can move the card or remove it.',
                      )}
            </p>
        );
    }

    return (
        <>
            <Collapsible
                defaultOpen={hostsOf(attrs.origins).length > 0}
                className="group grid gap-2"
            >
                <CollapsibleTrigger className="text-small text-ink-2 hover:text-ink flex items-center gap-1 text-left">
                    <ChevronRight
                        aria-hidden="true"
                        className="size-4 transition-transform group-data-[state=open]:rotate-90"
                    />
                    {__('More: hosts this code loads from')}
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <CardField
                        label={__('Hosts it connects to')}
                        hint={__(
                            'One a line, such as securepubads.g.doubleclick.net. Hosts the code names itself are found without this.',
                        )}
                    >
                        {(props) => (
                            <Textarea
                                {...props}
                                rows={3}
                                autoGrow
                                spellCheck={false}
                                value={hosts}
                                onChange={(event) => {
                                    setHosts(event.target.value);
                                    updateAttributes({
                                        origins: event.target.value
                                            .split('\n')
                                            .map((host) => host.trim())
                                            .filter((host) => host !== ''),
                                    });
                                }}
                                className="font-mono"
                            />
                        )}
                    </CardField>
                </CollapsibleContent>
            </Collapsible>
            <p className="text-small text-ink-3">
                {__(
                    'Written exactly as pasted; each card is its own ad unit. Saving or publishing a change asks for your password again.',
                )}
            </p>
        </>
    );
}
