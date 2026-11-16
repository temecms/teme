import { Link, usePage } from '@inertiajs/react';
import type { ReactNodeViewProps } from '@tiptap/react';
import { CodeXml } from 'lucide-react';
import { useId, useState } from 'react';
import { Button } from '@/components/ui/button';
import { IconTile } from '@/components/ui/icon-tile';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { StatusPill } from '@/components/ui/status-pill';
import { __, __n } from '@/lib/i18n';
import type { ContentEditPage, PlacedCode } from '@/types';
import { CardFrame } from './card-frame';
import type { CardSettingsProps } from './card-environment';

/** The site code entries placed with a card, by name, as the editor's region mode sends them; none anywhere else. */
function usePlacedCode(): PlacedCode[] {
    return usePage<ContentEditPage>().props.placedCode ?? [];
}

/** Settings › Site code's address, for someone who may open it; null for anyone else. */
function useSiteCodeHref(): string | null {
    return (
        usePage().props.settingsNav.find((item) => item.key === 'site-code')
            ?.href ?? null
    );
}

/**
 * Custom HTML (I-3127): a Settings › Site code entry placed by name. Only its
 * ID is stored; an editor chooses it here and never sees or changes its
 * code, which the site draws exactly as an administrator wrote it.
 */
export function SiteCodeCard({
    node,
    selected,
    updateAttributes,
    deleteNode,
}: ReactNodeViewProps) {
    const placed = usePlacedCode();
    const locked = usePage<ContentEditPage>().props.siteCodeLocked === true;
    const entryId = (node.attrs.entryId as string | null) ?? '';
    const entry = placed.find((item) => item.id === entryId) ?? null;
    // The frame keeps its panel's state; each ask to choose draws it again, open.
    const [opened, setOpened] = useState(0);
    const choose = (): void => setOpened((current) => current + 1);
    const chooseLabel = entryId === '' ? __('Choose code') : __('Change code');

    let state: { pill: 'On' | 'Off' | null; words: string };

    if (locked) {
        state = {
            pill: null,
            words: __(
                'The host has switched site code off, so readers see nothing here.',
            ),
        };
    } else if (entry === null) {
        state = {
            pill: null,
            words: __('This code was deleted, so readers see nothing here.'),
        };
    } else if (entry.on) {
        state = {
            pill: 'On',
            words: __('Readers see it here, drawn exactly as it was written.'),
        };
    } else {
        state = {
            pill: 'Off',
            words: __("This code is off, so readers don't see it."),
        };
    }

    return (
        <CardFrame
            key={opened}
            label={__('Custom HTML')}
            selected={selected}
            onRemove={deleteNode}
            defaultOpen={opened > 0 || entryId === ''}
            preview={entry?.name}
            tools={
                <Button
                    type="button"
                    variant="ghost"
                    size="xs"
                    onClick={choose}
                >
                    {chooseLabel}
                </Button>
            }
            settings={
                <SiteCodeSettings
                    attrs={node.attrs}
                    update={updateAttributes}
                />
            }
        >
            <div className="flex min-w-0 items-start gap-3">
                <IconTile icon={CodeXml} />
                <div className="grid min-w-0 gap-1">
                    {entryId === '' ? (
                        <>
                            <p className="text-ink font-semibold">
                                {__('Choose the code to show')}
                            </p>
                            <p className="text-ink-2 text-small">
                                {placed.length === 0
                                    ? __('No code is placed with a card yet.')
                                    : __n(
                                          ':count entry is placed with a card.|:count entries are placed with a card.',
                                          placed.length,
                                      )}
                            </p>
                            <div>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="xs"
                                    onClick={choose}
                                >
                                    {__('Choose…')}
                                </Button>
                            </div>
                        </>
                    ) : (
                        <>
                            {entry !== null && (
                                <p className="text-ink truncate font-semibold">
                                    {entry.name}
                                </p>
                            )}
                            <p className="text-ink-2 text-small flex flex-wrap items-center gap-2">
                                {state.pill === 'On' && (
                                    <StatusPill tone="ok">
                                        {__('On')}
                                    </StatusPill>
                                )}
                                {state.pill === 'Off' && (
                                    <StatusPill tone="neutral">
                                        {__('Off')}
                                    </StatusPill>
                                )}
                                <span>{state.words}</span>
                            </p>
                        </>
                    )}
                </div>
            </div>
        </CardFrame>
    );
}

/** A Custom HTML card's settings: the placed entries, by name, one at a time. */
export function SiteCodeSettings({
    attrs,
    update: updateAttributes,
}: CardSettingsProps) {
    const placed = usePlacedCode();
    const href = useSiteCodeHref();
    const id = useId();
    const entryId = (attrs.entryId as string | null) ?? '';

    return (
        <>
            {placed.length === 0 ? (
                <p className="text-ink-2 text-small">
                    {__(
                        'No code is placed with a card yet. An administrator adds it in Settings › Site code.',
                    )}
                </p>
            ) : (
                <div className="grid gap-2">
                    <Label id={`${id}-label`}>{__('Code to show')}</Label>
                    <RadioGroup
                        aria-labelledby={`${id}-label`}
                        aria-describedby={`${id}-hint`}
                        value={entryId}
                        onValueChange={(next) =>
                            updateAttributes({ entryId: next })
                        }
                    >
                        {placed.map((entry) => (
                            <div
                                key={entry.id}
                                className="flex min-w-0 items-center gap-2"
                            >
                                <RadioGroupItem
                                    id={`${id}-${entry.id}`}
                                    value={entry.id}
                                />
                                <Label
                                    htmlFor={`${id}-${entry.id}`}
                                    className="min-w-0 truncate font-normal"
                                >
                                    {entry.name}
                                </Label>
                                {!entry.on && (
                                    <StatusPill tone="neutral">
                                        {__('Off')}
                                    </StatusPill>
                                )}
                            </div>
                        ))}
                    </RadioGroup>
                    <p id={`${id}-hint`} className="text-small text-ink-3">
                        {__(
                            "Code placed with a card in Settings › Site code, by name. The card shows it exactly as written; it can't be edited here.",
                        )}
                    </p>
                </div>
            )}
            {href !== null && (
                <p className="text-small text-ink-3">
                    <Link href={href} className="text-accent underline">
                        {__('Settings › Site code')}
                    </Link>
                    {__(
                        ' · where administrators write the code and mark it “Placed with a card”.',
                    )}
                </p>
            )}
        </>
    );
}
