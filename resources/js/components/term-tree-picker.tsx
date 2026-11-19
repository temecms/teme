import { http } from '@inertiajs/react';
import { useId, useMemo, useState } from 'react';
import TermController from '@/actions/App/Http/Admin/Taxonomy/TermController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ScrollArea } from '@/components/ui/scroll-area';
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** One term of a hierarchical taxonomy (TermPicker::tree). */
export type TreeTerm = { id: string; name: string; parentId: string | null };

/** A search field appears once the taxonomy has more terms than this. */
const SEARCH_AFTER = 8;

type Row = { term: TreeTerm; depth: number };

/**
 * The terms as rows, each child indented under its parent. Checked terms
 * and their parents come first; with a query, only matches and their
 * parents are shown.
 */
function rowsOf(terms: TreeTerm[], checked: string[], query: string): Row[] {
    const byId = new Map(terms.map((term) => [term.id, term]));
    const children = new Map<string | null, TreeTerm[]>();

    for (const term of terms) {
        const parent =
            term.parentId !== null && byId.has(term.parentId)
                ? term.parentId
                : null;
        children.set(parent, [...(children.get(parent) ?? []), term]);
    }

    // A term is kept when it, or something under it, matches.
    const needle = query.trim().toLowerCase();
    const keeps = (term: TreeTerm): boolean =>
        needle === '' ||
        term.name.toLowerCase().includes(needle) ||
        (children.get(term.id) ?? []).some(keeps);

    const holdsChecked = (term: TreeTerm): boolean =>
        checked.includes(term.id) ||
        (children.get(term.id) ?? []).some(holdsChecked);

    const rows: Row[] = [];
    const visit = (parent: string | null, depth: number) => {
        const siblings = (children.get(parent) ?? []).filter(keeps);
        const ordered = [
            ...siblings.filter(holdsChecked),
            ...siblings.filter((term) => !holdsChecked(term)),
        ];

        for (const term of ordered) {
            rows.push({ term, depth });
            visit(term.id, depth + 1);
        }
    };

    visit(null, 0);

    return rows;
}

/**
 * A hierarchical taxonomy's terms as a tree of checkboxes. The list scrolls
 * past 176px, offers a search once there are more than eight terms, lists
 * checked terms first, and ends with + Add for someone who may add terms.
 */
export function TermTreePicker({
    label,
    labelShown = false,
    segment,
    terms: initial,
    value,
    onChange,
    canAdd,
}: {
    label: string;
    /** Whether the taxonomy's name shows over the list, as in the editor's rail. */
    labelShown?: boolean;
    segment: string;
    terms: TreeTerm[];
    value: string[];
    onChange: (ids: string[]) => void;
    canAdd: boolean;
}) {
    const [terms, setTerms] = useState(initial);
    const [query, setQuery] = useState('');
    const [adding, setAdding] = useState(false);
    const [name, setName] = useState('');
    const [parentId, setParentId] = useState('');
    const [problem, setProblem] = useState<string | null>(null);
    const id = useId();
    const rows = useMemo(
        () => rowsOf(terms, value, query),
        [terms, value, query],
    );

    const toggle = (termId: string, on: boolean) =>
        onChange(
            on
                ? [...value, termId]
                : value.filter((chosen) => chosen !== termId),
        );

    const add = async () => {
        setProblem(null);

        try {
            const response = await http.getClient().request({
                method: 'post',
                url: TermController.store(segment).url,
                data: { name, parent_id: parentId },
                headers: { Accept: 'application/json' },
            });
            const term = JSON.parse(response.data) as TreeTerm;

            setTerms((current) => [...current, term]);
            onChange([...value, term.id]);
            setName('');
            setParentId('');
            setAdding(false);
        } catch {
            setProblem(
                __('Couldn’t add it. Check that the name isn’t already used.'),
            );
        }
    };

    return (
        <fieldset className="grid gap-2">
            <legend
                className={cn(
                    labelShown
                        ? 'text-small text-ink-2 mb-1 font-medium'
                        : 'sr-only',
                )}
            >
                {label}
            </legend>
            {terms.length > SEARCH_AFTER && (
                <Input
                    aria-label={__('Search :plural', {
                        plural: label.toLowerCase(),
                    })}
                    placeholder={__('Search')}
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    className="h-8"
                />
            )}
            <ScrollArea className="max-h-44">
                <ul className="p-1">
                    {rows.map(({ term, depth }) => (
                        <li
                            key={term.id}
                            className="flex items-center gap-2 py-1"
                            style={{ paddingLeft: depth * 16 }}
                        >
                            <Checkbox
                                id={`${id}-${term.id}`}
                                checked={value.includes(term.id)}
                                onCheckedChange={(on) =>
                                    toggle(term.id, on === true)
                                }
                            />
                            <Label
                                htmlFor={`${id}-${term.id}`}
                                className="text-body font-normal"
                            >
                                {term.name}
                            </Label>
                        </li>
                    ))}
                    {rows.length === 0 && (
                        <li className="text-ink-2 text-small py-1">
                            {terms.length === 0
                                ? __('None yet.')
                                : __('Nothing matches.')}
                        </li>
                    )}
                </ul>
            </ScrollArea>
            {canAdd &&
                (adding ? (
                    <div className="border-line grid gap-2 border-t pt-2">
                        <Label htmlFor={`${id}-name`}>{__('Name')}</Label>
                        <Input
                            id={`${id}-name`}
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            className="h-8"
                        />
                        <Label htmlFor={`${id}-parent`}>{__('Parent')}</Label>
                        <SelectField
                            id={`${id}-parent`}
                            value={parentId}
                            onValueChange={setParentId}
                            emptyLabel={__('None')}
                            options={terms.map((term) => ({
                                value: term.id,
                                label: term.name,
                            }))}
                            className="h-8 w-full"
                        />
                        {problem && (
                            <p
                                role="alert"
                                className="text-danger-ink text-caption"
                            >
                                {problem}
                            </p>
                        )}
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                size="sm"
                                disabled={name.trim() === ''}
                                onClick={() => void add()}
                            >
                                {__('Add')}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => setAdding(false)}
                            >
                                {__('Cancel')}
                            </Button>
                        </div>
                    </div>
                ) : (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="justify-self-start"
                        onClick={() => setAdding(true)}
                    >
                        {__('+ Add')}
                    </Button>
                ))}
        </fieldset>
    );
}
