import { usePage } from '@inertiajs/react';
import type { ReactNodeViewProps } from '@tiptap/react';
import { CodeXml } from 'lucide-react';
import { useId, useState } from 'react';
import { IconTile } from '@/components/ui/icon-tile';
import { Label } from '@/components/ui/label';
import { StatusPill } from '@/components/ui/status-pill';
import { Switch } from '@/components/ui/switch';
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
    const on = node.attrs.on !== false;

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
    } else if (on) {
        words = __('Readers see it here, drawn exactly as it was pasted.');
    } else {
        words = __("This code is off, so readers don't see it.");
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
                    <p className="text-ink-2 text-small flex flex-wrap items-center gap-2">
                        {manages && !locked && code !== '' && (
                            <StatusPill tone={on ? 'ok' : 'neutral'}>
                                {on ? __('On') : __('Off')}
                            </StatusPill>
                        )}
                        <span>{words}</span>
                    </p>
                </div>
                {manages && !locked && (
                    <Textarea
                        aria-label={__('Code')}
                        rows={4}
                        spellCheck={false}
                        value={code}
                        placeholder={__('<script async src="…"></script>')}
                        onChange={(event) =>
                            updateAttributes({ code: event.target.value })
                        }
                        className="bg-surface-2 text-small field-sizing-content resize-none rounded-sm border-0 px-3 py-2 font-mono"
                    />
                )}
            </div>
        </CardFrame>
    );
}

/**
 * A Custom HTML card's settings, for someone who may manage site code: the
 * hosts its code connects to and whether it is on. Anyone else is told who
 * may change it.
 */
export function SiteCodeSettings({
    attrs,
    update: updateAttributes,
}: CardSettingsProps) {
    const { manages, locked } = useCodeRights();
    const id = useId();
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
            <div className="flex items-center gap-2">
                <Switch
                    id={`${id}-on`}
                    checked={attrs.on !== false}
                    onCheckedChange={(next) => updateAttributes({ on: next })}
                />
                <Label htmlFor={`${id}-on`} className="font-normal">
                    {__('Show it on the site')}
                </Label>
            </div>
            <p className="text-small text-ink-3">
                {__(
                    'Written exactly as pasted; each card is its own ad unit. Saving or publishing a change asks for your password again.',
                )}
            </p>
        </>
    );
}
