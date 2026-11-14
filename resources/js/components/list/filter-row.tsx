import { useHttp } from '@inertiajs/react';
import { ListFilter } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import type { ComboboxOption } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { __ } from '@/lib/i18n';
import { search as searchTerms } from '@/routes/terms';
import { useCards } from './use-cards';

/** The value of every filter's "All …" first choice. */
const ALL = '';

/**
 * A list's filter row (docs/architecture/design-system.md, section 4.26):
 * the search, then the filters, then Clear filters once any is on, then, at
 * the end, the column chooser. It wraps on a narrow screen; on a phone the
 * filters fold into "Filters (n)", a sheet of the same controls, each
 * applying at once, with Clear filters at its foot, and the chooser is left
 * out, since cards have no columns.
 */
export function FilterRow({
    search,
    filters,
    active,
    clear,
    end,
}: {
    /** The search form. */
    search: ReactNode;
    /** The filters, each labelled for a screen reader. */
    filters?: ReactNode;
    /** How many filters are on, for "Filters (n)". */
    active: number;
    /** Clear filters, drawn once any is on. */
    clear?: ReactNode;
    /** At the end of the row, such as the column chooser; left out on a phone. */
    end?: ReactNode;
}) {
    const phone = useCards();
    const [open, setOpen] = useState(false);

    if (phone) {
        return (
            <div data-test="list-filters" className="grid gap-2">
                {search}
                {filters !== undefined && (
                    <Sheet open={open} onOpenChange={setOpen}>
                        <Button
                            type="button"
                            variant="neutral"
                            data-test="filters-sheet"
                            className="justify-self-start"
                            onClick={() => setOpen(true)}
                        >
                            <ListFilter aria-hidden="true" />
                            {__('Filters (:count)', { count: active })}
                        </Button>
                        <SheetContent side="bottom" data-test="filters-panel">
                            <SheetHeader>
                                <SheetTitle>{__('Filters')}</SheetTitle>
                                <SheetDescription className="sr-only">
                                    {__('Each choice applies at once.')}
                                </SheetDescription>
                            </SheetHeader>
                            <div className="grid gap-3 px-4">{filters}</div>
                            {active > 0 && clear !== undefined && (
                                <SheetFooter>{clear}</SheetFooter>
                            )}
                        </SheetContent>
                    </Sheet>
                )}
            </div>
        );
    }

    return (
        <div
            data-test="list-filters"
            className="flex min-w-0 flex-wrap items-center gap-2"
        >
            {search}
            {filters}
            {active > 0 && clear}
            <span className="flex-1" />
            {end}
        </div>
    );
}

/**
 * One filter that picks from a list the page already has, such as the
 * authors or a nesting taxonomy's terms: a Combobox the person can type into
 * (I-1452), its "All …" choice first. Choosing applies at once.
 */
export function ChoiceFilter({
    label,
    allLabel,
    options,
    value,
    onChange,
    testId,
}: {
    /** The filter's name, for a screen reader, such as "Author". */
    label: string;
    /** The first choice, such as "All authors". */
    allLabel: string;
    options: ComboboxOption[];
    /** The value in force, undefined for none. */
    value: string | undefined;
    onChange: (value: string | null) => void;
    testId?: string;
}) {
    const id = useId();
    const all: ComboboxOption = { value: ALL, label: allLabel };
    const chosen =
        value === undefined
            ? all
            : (options.find((option) => option.value === value) ?? all);

    return (
        <div data-test={testId} className="grid w-full sm:w-48">
            <Label htmlFor={id} className="sr-only">
                {label}
            </Label>
            <Combobox
                id={id}
                options={[all, ...options]}
                value={chosen}
                placeholder={allLabel}
                searchPlaceholder={__('Search :name', {
                    name: label.toLocaleLowerCase(),
                })}
                onValueChange={(next) =>
                    onChange(
                        next === null || next.value === ALL ? null : next.value,
                    )
                }
            />
        </div>
    );
}

/**
 * The filter for a flat taxonomy, such as tags, which can number in the
 * thousands (I-1452): a Combobox that asks the server as the person types,
 * 200ms after typing stops, each request superseding the last, as the term
 * picker does. The term in force is shown by its name.
 */
export function TermSearchFilter({
    taxonomy,
    label,
    allLabel,
    value,
    valueName,
    onChange,
    testId,
}: {
    /** The taxonomy's key, such as tag. */
    taxonomy: string;
    label: string;
    allLabel: string;
    /** The term in force, by ID, undefined for none. */
    value: string | undefined;
    /** Its name, as the list's server gives it. */
    valueName: string | undefined;
    onChange: (value: string | null) => void;
    testId?: string;
}) {
    const id = useId();
    const { submit } = useHttp();
    const [query, setQuery] = useState('');
    const [loading, setLoading] = useState(false);
    const [results, setResults] = useState<ComboboxOption[]>([]);
    const latest = useRef(0);

    useEffect(() => {
        const asked = ++latest.current;

        if (query.trim() === '') {
            setResults([]);
            setLoading(false);

            return;
        }

        setLoading(true);

        // The previous request is superseded: only the latest one's answer is kept.
        const timer = setTimeout(async () => {
            try {
                const found = (await submit(
                    searchTerms({ query: { taxonomy, q: query } }),
                )) as { id: string; name: string; path: string }[];

                if (asked === latest.current) {
                    setResults(
                        found.map((term) => ({
                            value: term.id,
                            label: term.name,
                            description:
                                term.path !== term.name ? term.path : undefined,
                        })),
                    );
                }
            } finally {
                if (asked === latest.current) {
                    setLoading(false);
                }
            }
        }, 200);

        return () => clearTimeout(timer);
    }, [query, taxonomy, submit]);

    const all: ComboboxOption = { value: ALL, label: allLabel };
    const chosen =
        value === undefined
            ? all
            : { value, label: valueName ?? __('Not found') };

    return (
        <div data-test={testId} className="grid w-full sm:w-48">
            <Label htmlFor={id} className="sr-only">
                {label}
            </Label>
            <Combobox
                id={id}
                options={query.trim() === '' ? [all] : results}
                value={chosen}
                placeholder={allLabel}
                searchPlaceholder={__('Search :name', {
                    name: label.toLocaleLowerCase(),
                })}
                emptyText={loading ? undefined : __('No matches')}
                search={{
                    value: query,
                    onChange: (next) => {
                        // Loading from the keystroke on, so Enter never takes the last search's answer.
                        setQuery(next);
                        setLoading(next.trim() !== '');
                    },
                    loading,
                }}
                onValueChange={(next) => {
                    setQuery('');
                    onChange(
                        next === null || next.value === ALL ? null : next.value,
                    );
                }}
            />
        </div>
    );
}
