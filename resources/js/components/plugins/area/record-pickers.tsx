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

/**
 * A picker of posts and pages, stored as their content IDs, searching as the
 * person types. Only items they may open are offered; a chosen one stays in
 * the chips, and a search that can't run says so, with Try again.
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
    const labels = useRef<Record<string, string>>({});
    const chosen = list(value);

    for (const chip of field.chips) {
        labels.current[chip.value] = chip.label ?? '';
    }

    const search = useSearch(query, async (typed) => {
        try {
            const answer = await send(
                ItemChoicesController({ query: { q: typed } }),
            );

            if (answer.status !== 200) {
                return { ok: false, options: [] };
            }

            const body = answer.body as {
                shown: {
                    id: string;
                    title: string;
                    kind: string;
                    status: string;
                    date: string;
                }[];
            };

            return {
                ok: true,
                options: body.shown.map((item) => {
                    labels.current[item.id] = item.title;

                    return {
                        value: item.id,
                        label: item.title,
                        description: `${item.kind} · ${day(item.date)}${item.status === 'published' ? '' : ` · ${__('Draft')}`}`,
                        disabled: chosen.includes(item.id),
                    };
                }),
            };
        } catch {
            return { ok: false, options: [] };
        }
    });

    const option = (itemId: string): ComboboxOption => ({
        value: itemId,
        label: labels.current[itemId] ?? itemId,
    });

    return (
        <div className="grid gap-2" data-test={`items-picker-${field.key}`}>
            <Combobox
                id={id}
                multiple
                options={search.options}
                value={chosen.map(option)}
                onValueChange={(next) => onChange(next.map((one) => one.value))}
                removeLabel={(item) =>
                    __('Remove :title', { title: item.label })
                }
                placeholder={__('Choose posts and pages')}
                searchPlaceholder={__('Search posts and pages')}
                search={{
                    value: query,
                    onChange: setQuery,
                    loading: search.loading,
                }}
                emptyText={
                    query.trim() === ''
                        ? __(
                              'Type a few words from the title. Only posts and pages you can open are listed.',
                          )
                        : __(
                              'No posts and pages match “:query”. Check the spelling, or try fewer words.',
                              { query: query.trim() },
                          )
                }
            />
            {search.failed && (
                <Notice
                    tone="danger"
                    role="alert"
                    data-test="items-picker-failed"
                >
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
            {field.description !== '' && (
                <p className="text-small text-ink-2">{field.description}</p>
            )}
        </div>
    );
}

/**
 * A terms field that stores each term's slug, searching the taxonomy as the
 * person types. A slug no term has any more is shown as stale, with a
 * warning and the words to act on.
 */
export function SlugTermsPicker({
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
                slug: string;
                name: string;
                path: string;
            }[];

            return {
                ok: true,
                options: terms.map((term) => ({
                    value: term.slug,
                    label: term.name,
                    description: `${__('Slug')}: ${term.slug}`,
                    disabled: chosen.includes(term.slug),
                })),
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
                              'Type a few letters of a name. Only the site’s own :plural are listed.',
                              { plural: field.label.toLowerCase() },
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
                              'This site has no term with the slug “:slug” now. It was probably renamed or deleted, so the rule can’t match it. Remove it, or choose the term again.',
                              { slug: stale[0] },
                          )
                        : __(
                              'This site has no terms with the slugs “:slugs” now. They were probably renamed or deleted. Remove them, or choose the terms again.',
                              { slugs: stale.join('”, “') },
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
