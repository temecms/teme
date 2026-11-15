import type { ReactNodeViewProps } from '@tiptap/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ScrollArea } from '@/components/ui/scroll-area';
import { SelectField } from '@/components/ui/select-field';
import { cn } from '@/lib/utils';
import { useLinkTargets } from '../use-link-targets';
import { CardField, CardFrame, TextSetting } from './card-frame';
import { optionsFor, useCardEnvironment } from './card-environment';
import type { CardSettingsProps } from './card-environment';
import { shownTitle } from '@/lib/titles';
import { __ } from '@/lib/i18n';

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
            label={__('Button')}
            selected={selected}
            warning={
                label.trim() === ''
                    ? { label: __('Needs a label') }
                    : leadsNowhere
                      ? { label: __('Needs a destination') }
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
                    {label || __('Button')}
                </span>
                <span className="text-ink-2 text-caption truncate">
                    {contentId
                        ? __('Leads to an item on this site')
                        : url || __('Leads nowhere yet')}
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
                label={__('Label')}
                value={label}
                onChange={(next) => updateAttributes({ label: next })}
            />
            <CardField
                label={__('Link to something on this site')}
                hint={__('The button follows it if its address changes.')}
            >
                {(props) => (
                    <div className="grid gap-1">
                        <Input
                            {...props}
                            value={query}
                            placeholder={__('Search by title')}
                            onFocus={() => setSearching(true)}
                            onChange={(event) => setQuery(event.target.value)}
                            className="text-small h-8.5"
                        />
                        {searching && targets.length > 0 && (
                            <ScrollArea className="border-line max-h-40 rounded-md border">
                                <ul>
                                    {targets.map((target) => (
                                        <li key={target.id}>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                onClick={() => {
                                                    updateAttributes({
                                                        contentId: target.id,
                                                        url: null,
                                                    });
                                                    setChosenTitle(
                                                        target.title,
                                                    );
                                                    setSearching(false);
                                                }}
                                                className="text-ink text-small h-auto w-full justify-start px-2 py-2 text-left font-normal whitespace-normal"
                                            >
                                                {shownTitle(target.title)}
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </ScrollArea>
                        )}
                    </div>
                )}
            </CardField>
            <TextSetting
                label={__('Or an address')}
                hint={__('An address on another site.')}
                value={contentId ? '' : url}
                placeholder={__('https://')}
                onChange={(next) => {
                    updateAttributes({
                        url: next === '' ? null : next,
                        contentId: null,
                    });
                    setChosenTitle(null);
                }}
            />
            <CardField label={__('Style')}>
                {(props) => (
                    <SelectField
                        {...props}
                        value={style}
                        onValueChange={(next) =>
                            updateAttributes({ style: next })
                        }
                        options={optionsFor(manifest, 'button', 'style')}
                        className="h-8.5 w-full max-w-40"
                    />
                )}
            </CardField>
        </>
    );
}
