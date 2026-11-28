import { RotateCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import ItemChoicesController from '@/actions/App/Http/Admin/Contents/ItemChoicesController';
import TermSearchController from '@/actions/App/Http/Admin/Taxonomy/TermSearchController';
import { send } from '@/components/editor/editor-http';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import type { ComboboxOption } from '@/components/ui/combobox';
import { Notice } from '@/components/ui/notice';
import { adminLocale, siteZone } from '@/lib/dates';
import { __ } from '@/lib/i18n';
import type { AreaField } from '@/types/plugin-area';

/** How long a search waits for the person to stop typing, in milliseconds. */
const SEARCH_WAIT_MS = 250;

/** A stored value as its list of strings. */
function list(value: unknown): string[] {
    return Array.isArray(value)
        ? value.filter((one): one is string => typeof one === 'string')
        : [];
}

/** A date as the admin's language shows it, in the site's zone: "13 Nov 2026". */
function day(iso: string): string {
    return new Intl.DateTimeFormat(adminLocale(), {
        timeZone: siteZone(),
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(iso));
}

/**
 * Searches as the person types, after a short wait, and hands back what it
 * found, or that the search couldn't run. A query that is cleared answers
 * at once with nothing.
 */
function useSearch(
    query: string,
    ask: (query: string) => Promise<{ ok: boolean; options: ComboboxOption[] }>,
) {
    const [options, setOptions] = useState<ComboboxOption[]>([]);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [retry, setRetry] = useState(0);
    const asked = useRef(ask);
    asked.current = ask;

    useEffect(() => {
        if (query.trim() === '') {
            setOptions([]);
            setFailed(false);
            setLoading(false);

            return;
        }

        let stale = false;
        setLoading(true);
        const timer = window.setTimeout(() => {
            void asked.current(query.trim()).then((answer) => {
                if (stale) {
                    return;
                }

                setFailed(!answer.ok);
                setOptions(answer.options);
                setLoading(false);
            });
        }, SEARCH_WAIT_MS);

        return () => {
            stale = true;
            window.clearTimeout(timer);
        };
    }, [query, retry]);

    return { options, loading, failed, again: () => setRetry((n) => n + 1) };
}

/** One item the search returns: what a picker shows of a post or page. */
type FoundItem = {
    id: string;
    title: string;
    kind: string;
    status: string;
    date: string;
    filedUnder: string[];
};

/**
 * The posts and pages a search finds for the words typed, after a short
 * wait: the shown items, how many match in all, and whether the search ran.
 */
function useItemSearch(query: string) {
    const [items, setItems] = useState<FoundItem[]>([]);
    const [total, setTotal] = useState(0);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [retry, setRetry] = useState(0);

    useEffect(() => {
        const typed = query.trim();

        if (typed === '') {
            setItems([]);
            setTotal(0);
            setFailed(false);
            setLoading(false);

            return;
        }

        let stale = false;
        setLoading(true);
        const timer = window.setTimeout(() => {
            send(ItemChoicesController({ query: { q: typed } }))
                .then((answer) => {
                    if (stale) {
                        return;
                    }

                    if (answer.status !== 200) {
                        setFailed(true);
                        setItems([]);
                        setTotal(0);
                    } else {
                        const body = answer.body as unknown as {
                            shown: FoundItem[];
                            total: number;
                        };
                        setFailed(false);
                        setItems(body.shown);
                        setTotal(body.total);
                    }

                    setLoading(false);
                })
                .catch(() => {
                    if (!stale) {
                        setFailed(true);
                        setItems([]);
                        setLoading(false);
                    }
                });
        }, SEARCH_WAIT_MS);

        return () => {
            stale = true;
            window.clearTimeout(timer);
        };
    }, [query, retry]);

    return {
        items,
        total,
        loading,
        failed,
        again: () => setRetry((n) => n + 1),
    };
}

/**
 * A picker of posts and pages, stored as their content IDs, in the shared
 * combobox: chips of what is chosen, and a search whose results show the words
 * typed, a Draft pill, the kind and categories and date, and a Chosen mark on
 * those already picked. Only items the person may open are offered; a search
 * that can't run says so, with Try again.
 */
export function ItemsPicker({
    id,
    field,
    value,
    onChange,
}: {
    id: string;
    field: AreaField;
    value: unknown;
    onChange: (value: unknown) => void;
}) {
    const [query, setQuery] = useState('');
    const titles = useRef<Record<string, string>>({});
    const chosen = list(value);
    const search = useItemSearch(query);

    for (const chip of field.chips) {
        titles.current[chip.value] = chip.label ?? '';
    }

    const options: ComboboxOption[] = search.items.map((item) => {
        titles.current[item.id] = item.title;

        return {
            value: item.id,
            label: item.title,
            description: [
                item.kind,
                item.filedUnder.length > 0 ? item.filedUnder.join(', ') : null,
                day(item.date),
            ]
                .filter((part) => part !== null)
                .join(' · '),
            pill: item.status === 'published' ? undefined : __('Draft'),
            marker: chosen.includes(item.id) ? __('Chosen') : undefined,
            disabled: chosen.includes(item.id),
        };
    });

    const chosenOptions: ComboboxOption[] = chosen.map((itemId) => ({
        value: itemId,
        label: titles.current[itemId] ?? itemId,
    }));

    const typed = query.trim() !== '';

    return (
        <div className="grid gap-2" data-test={`items-picker-${field.key}`}>
            <Combobox
                id={id}
                multiple
                options={options}
                value={chosenOptions}
                onValueChange={(next) => onChange(next.map((one) => one.value))}
                removeLabel={(item) => __('Remove :title', { title: item.label })}
                placeholder={__('Search posts and pages')}
                searchPlaceholder={__('Search posts and pages')}
                search={{
                    value: query,
                    onChange: setQuery,
                    loading: search.loading,
                }}
                searchingText={__('Searching posts and pages…')}
                highlight={query}
                footer={
                    search.total > search.items.length
                        ? __('Showing :shown of :total. Type more of the title to narrow it.', {
                              shown: search.items.length,
                              total: search.total,
                          })
                        : undefined
                }
                emptyText={
                    typed
                        ? __('No posts and pages match “:query”. Check the spelling, or try fewer words.', {
                              query: query.trim(),
                          })
                        : __('Type a few words from the title. Only posts and pages you can open are listed.')
                }
            />
            {typed && !search.loading && search.failed && (
                <Notice tone="danger" role="alert" data-test="items-picker-failed">
                    <p>
                        {__('The search couldn’t run this time. Nothing you chose was lost. Try again in a moment.')}
                    </p>
                    <Button type="button" size="sm" variant="secondary" onClick={search.again}>
                        <RotateCw aria-hidden className="size-4" />
                        {__('Try again')}
                    </Button>
                </Notice>
            )}
            {field.description !== '' && <p className="text-small text-ink-2">{field.description}</p>}
        </div>
    );
}

/**
 * A terms field that stores each term's slug or ID, searching the taxonomy as the
 * person types. A slug no term has any more is shown as stale, with a
 * warning and the words to act on.
 */
export function TermsPicker({
    id,
    field,
    value,
    onChange,
}: {
    id: string;
    field: AreaField;
    value: unknown;
    onChange: (value: unknown) => void;
}) {
    const [query, setQuery] = useState('');
    const chosen = list(value);
    const names = Object.fromEntries(
        field.chips.map((chip) => [chip.value, chip.label]),
    ) as Record<string, string | null>;
    const taxonomy = field.taxonomy ?? '';

    const search = useSearch(query, async (typed) => {
        try {
            const answer = await send(
                TermSearchController({ query: { taxonomy, q: typed } }),
            );

            if (answer.status !== 200) {
                return { ok: false, options: [] };
            }

            const terms = answer.body as unknown as {
                id: string;
                slug: string;
                name: string;
                path: string;
            }[];

            // A term stored by slug is chosen by its slug, one stored by ID by its ID.
            return {
                ok: true,
                options: terms.map((term) => {
                    const value =
                        field.storeAs === 'slug' ? term.slug : term.id;

                    return {
                        value,
                        label: term.name,
                        description:
                            field.storeAs === 'slug'
                                ? __('Slug: :slug', { slug: term.slug })
                                : term.path,
                        disabled: chosen.includes(value),
                    };
                }),
            };
        } catch {
            return { ok: false, options: [] };
        }
    });

    const stale = field.stale;

    return (
        <div className="grid gap-2" data-test={`terms-picker-${field.key}`}>
            <Combobox
                id={id}
                multiple
                options={search.options}
                value={chosen.map((slug) => ({
                    value: slug,
                    label: names[slug] ?? slug,
                }))}
                onValueChange={(next) => onChange(next.map((one) => one.value))}
                removeLabel={(item) => __('Remove :name', { name: item.label })}
                placeholder={__('Search the site’s :plural', {
                    plural: field.label.toLowerCase(),
                })}
                searchPlaceholder={__('Search the site’s :plural', {
                    plural: field.label.toLowerCase(),
                })}
                search={{
                    value: query,
                    onChange: setQuery,
                    loading: search.loading,
                }}
                emptyText={
                    query.trim() === ''
                        ? __(
                              'Type a few letters of a :singular’s name. Only the site’s own :plural are listed.',
                              {
                                  singular: field.taxonomySingular ?? '',
                                  plural: field.label.toLowerCase(),
                              },
                          )
                        : __(
                              'No :plural match “:query”. Check the spelling, or try fewer words.',
                              {
                                  plural: field.label.toLowerCase(),
                                  query: query.trim(),
                              },
                          )
                }
            />
            {stale.length > 0 && (
                <Notice tone="warn" data-test="terms-picker-stale">
                    {stale.length === 1
                        ? __(
                              ':plugin saved “:slug”, and this site has no :singular with that slug now. It was probably renamed or deleted, so the tag rule can’t match it. Remove it, or choose the :singular again.',
                              {
                                  plugin: field.plugin ?? '',
                                  slug: stale[0],
                                  singular: field.taxonomySingular ?? '',
                              },
                          )
                        : __(
                              ':plugin saved “:slugs”, and this site has no :plural with those slugs now. It was probably renamed or deleted, so the tag rule can’t match them. Remove them, or choose the :plural again.',
                              {
                                  plugin: field.plugin ?? '',
                                  slugs: stale.join('”, “'),
                                  plural: field.label.toLowerCase(),
                              },
                          )}
                </Notice>
            )}
            {search.failed && (
                <Notice tone="danger" role="alert">
                    <p>
                        {__(
                            'The search couldn’t run this time. Nothing you chose was lost. Try again in a moment.',
                        )}
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={search.again}
                    >
                        {__('Try again')}
                    </Button>
                </Notice>
            )}
        </div>
    );
}
