import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useFieldControlContext } from '@/components/editor/fields/field-control';
import type { FieldControlProps } from '@/components/editor/fields/field-control';
import { useLinkTargets } from '@/components/editor/use-link-targets';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { __ } from '@/lib/i18n';
import { shownTitle } from '@/lib/titles';
import type { EditorEntry } from '@/types/editor';

/**
 * Another item on this site, stored by its ID (rule 9): the chosen item's
 * title, its type and whether it is published, with Change and Remove; or,
 * with none chosen, a search by title.
 */
export function EntryControl({
    value,
    onChange,
    id,
    describedBy,
    invalid,
}: FieldControlProps) {
    const { contentTypes } = usePage().props;
    const { entries } = useFieldControlContext();
    const [chosen, setChosen] = useState<Record<string, EditorEntry>>({});
    const [searching, setSearching] = useState(false);
    const [query, setQuery] = useState('');
    const itemId = typeof value === 'string' && value !== '' ? value : null;
    const targets = useLinkTargets(
        query,
        (itemId === null || searching) && query.trim() !== '',
    ).slice(0, 8);
    const item = itemId === null ? null : (chosen[itemId] ?? entries[itemId]);

    const kind = (entry: EditorEntry) => {
        const singular =
            contentTypes.find((type) => type.key === entry.type)?.singular ??
            entry.type;

        return entry.status === 'published'
            ? singular
            : __(':singular, not published', { singular });
    };

    if (itemId !== null && !searching) {
        return (
            <div
                id={id}
                aria-describedby={describedBy}
                className="border-edge bg-surface flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border px-3 py-2"
            >
                {item ? (
                    <span className="text-small">
                        {shownTitle(item.title)}{' '}
                        <span className="text-ink-2 text-caption">
                            {kind(item)}
                        </span>
                    </span>
                ) : (
                    <span className="text-ink-2 text-small">
                        {__('An item that no longer exists')}
                    </span>
                )}
                <span className="ml-auto flex gap-1">
                    {item && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setSearching(true)}
                        >
                            {__('Change')}
                        </Button>
                    )}
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="text-danger-ink"
                        onClick={() => onChange(null)}
                    >
                        {__('Remove')}
                    </Button>
                </span>
            </div>
        );
    }

    return (
        <div className="grid gap-2">
            <Input
                id={id}
                type="search"
                autoComplete="off"
                placeholder={__('Find an item by its title…')}
                aria-describedby={describedBy}
                aria-invalid={invalid || undefined}
                value={query}
                onChange={(event) => setQuery(event.target.value)}
            />
            {query.trim() !== '' && targets.length === 0 && (
                <p className="text-ink-2 text-caption">
                    {__('No items match.')}
                </p>
            )}
            {query.trim() !== '' && targets.length > 0 && (
                <ul className="grid gap-0.5">
                    {targets.map((target) => (
                        <li key={target.id}>
                            <Button
                                type="button"
                                variant="ghost"
                                data-test="entry-option"
                                onClick={() => {
                                    setChosen((known) => ({
                                        ...known,
                                        [target.id]: target,
                                    }));
                                    onChange(target.id);
                                    setQuery('');
                                    setSearching(false);
                                }}
                                className="text-ink text-small h-auto min-h-6 w-full items-baseline justify-start gap-2 rounded-sm px-2 text-left font-normal"
                            >
                                <span>{shownTitle(target.title)}</span>
                                <span className="text-ink-2 text-caption">
                                    {kind(target)}
                                </span>
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
