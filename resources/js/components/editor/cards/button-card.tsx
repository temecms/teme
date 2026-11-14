import type { ReactNodeViewProps } from '@tiptap/react';
import { useState } from 'react';
import { SelectField } from '@/components/ui/select-field';
import { cn } from '@/lib/utils';
import { useLinkTargets } from '../use-link-targets';
import { CardField, CardFrame, TextSetting } from './card-frame';
import { optionsFor, useCardEnvironment } from './card-environment';
import type { CardSettingsProps } from './card-environment';
import { shownTitle } from '@/lib/titles';

/**
 * A button: a label, and where it leads. An article is stored by its ID and
 * never as its address (rule 9), so the button follows the article when its
 * address changes; any other destination is an address typed in.
 */
export function ButtonCard({
    node,
    selected,
    updateAttributes,
    deleteNode,
}: ReactNodeViewProps) {
    const label = (node.attrs.label as string | null) ?? '';
    const url = (node.attrs.url as string | null) ?? '';
    const contentId = node.attrs.contentId as string | null;
    const style = (node.attrs.style as string | null) ?? 'primary';
    const leadsNowhere = !contentId && url === '';

    return (
        <CardFrame
            label="Button"
            selected={selected}
            warning={
                label.trim() === ''
                    ? { label: 'Needs a label' }
                    : leadsNowhere
                      ? { label: 'Needs a destination' }
                      : null
            }
            onRemove={deleteNode}
            defaultOpen={label === '' || leadsNowhere}
            settings={
                <ButtonSettings attrs={node.attrs} update={updateAttributes} />
            }
        >
            <div className="flex flex-wrap items-center gap-3">
                <span
                    className={cn(
                        'inline-flex h-9 items-center rounded-md px-4 text-[14px] font-medium',
                        style === 'secondary'
                            ? 'border-line border'
                            : 'bg-accent text-on-accent',
                    )}
                >
                    {label || 'Button'}
                </span>
                <span className="text-ink-2 truncate text-xs">
                    {contentId
                        ? 'Leads to an item on this site'
                        : url || 'Leads nowhere yet'}
                </span>
            </div>
        </CardFrame>
    );
}

/** A button card's settings, in the card's panel or the rail's inspector. */
export function ButtonSettings({
    attrs: raw,
    update: updateAttributes,
}: CardSettingsProps) {
    const { manifest } = useCardEnvironment();
    const label = (raw.label as string | null) ?? '';
    const url = (raw.url as string | null) ?? '';
    const contentId = raw.contentId as string | null;
    const style = (raw.style as string | null) ?? 'primary';
    const [query, setQuery] = useState('');
    const [searching, setSearching] = useState(false);
    const targets = useLinkTargets(query, searching);
    const [, setChosenTitle] = useState<string | null>(null);

    return (
        <>
            <TextSetting
                label="Label"
                value={label}
                onChange={(next) => updateAttributes({ label: next })}
            />
            <CardField
                label="Link to something on this site"
                hint="The button follows it if its address changes."
            >
                {(props) => (
                    <div className="grid gap-1">
                        <input
                            {...props}
                            value={query}
                            placeholder="Search by title"
                            onFocus={() => setSearching(true)}
                            onChange={(event) => setQuery(event.target.value)}
                            className="border-edge bg-surface focus-visible:ring-ring h-[34px] w-full rounded-md border px-3 text-[13px] outline-none focus-visible:ring-2"
                        />
                        {searching && targets.length > 0 && (
                            <ul className="border-line max-h-40 overflow-y-auto rounded-md border">
                                {targets.map((target) => (
                                    <li key={target.id}>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                updateAttributes({
                                                    contentId: target.id,
                                                    url: null,
                                                });
                                                setChosenTitle(target.title);
                                                setSearching(false);
                                            }}
                                            className="hover:bg-surface-3 focus-visible:ring-ring w-full px-2 py-1.5 text-left text-[13px] outline-none focus-visible:ring-2"
                                        >
                                            {shownTitle(target.title)}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
            </CardField>
            <TextSetting
                label="Or an address"
                hint="An address on another site."
                value={contentId ? '' : url}
                placeholder="https://"
                onChange={(next) => {
                    updateAttributes({
                        url: next === '' ? null : next,
                        contentId: null,
                    });
                    setChosenTitle(null);
                }}
            />
            <CardField label="Style">
                {(props) => (
                    <SelectField
                        {...props}
                        value={style}
                        onValueChange={(next) =>
                            updateAttributes({ style: next })
                        }
                        options={optionsFor(manifest, 'button', 'style')}
                        className="h-[34px] w-full max-w-40"
                    />
                )}
            </CardField>
        </>
    );
}
