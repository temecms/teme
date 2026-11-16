import { useHttp, usePage } from '@inertiajs/react';
import type { ReactNodeViewProps } from '@tiptap/react';
import { ArrowDown, ArrowUp, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import LinkedContentController from '@/actions/App/Http/Admin/Contents/LinkedContentController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { SelectField } from '@/components/ui/select-field';
import { Switch } from '@/components/ui/switch';
import { __, __n } from '@/lib/i18n';
import { shownTitle } from '@/lib/titles';
import { search as searchTerms } from '@/routes/terms';
import type { LinkedContent } from '@/types';
import { useLinkTargets } from '../use-link-targets';
import { CardField, CardFrame, TextSetting } from './card-frame';
import type { CardSettingsProps } from './card-environment';

/** The most posts one list shows (FeaturedPostsCard::MOST). */
const MOST = 12;

/** The region type, which no list can show. */
const REGION = 'region';

/** A term found by its name, with the taxonomy it belongs to. */
type FoundTerm = { id: string; name: string; taxonomy: string };

/** Terms' and posts' names learned while this editor is open, by ID. */
const termNames = new Map<string, string>();
const postTitles = new Map<string, string>();

type Attrs = {
    heading: string;
    source: string;
    items: string[];
    type: string;
    termId: string | null;
    count: number;
    images: boolean;
};

/** The card's attributes, each with its declared default when it lacks one. */
function read(raw: Record<string, unknown>): Attrs {
    return {
        heading: typeof raw.heading === 'string' ? raw.heading : '',
        source: raw.source === 'chosen' ? 'chosen' : 'latest',
        items: Array.isArray(raw.items)
            ? raw.items.filter((id): id is string => typeof id === 'string')
            : [],
        type: typeof raw.type === 'string' ? raw.type : '',
        termId: typeof raw.termId === 'string' ? raw.termId : null,
        count: typeof raw.count === 'number' ? raw.count : 5,
        images: raw.images !== false,
    };
}

/** The types a list may show: every one in the admin but a theme's region. */
function useListTypes(): { value: string; label: string; plural: string }[] {
    return usePage()
        .props.contentTypes.filter((type) => type.key !== REGION)
        .map((type) => ({
            value: type.key,
            label: type.singular,
            plural: type.plural,
        }));
}

/** The chosen posts' titles, read from content.linked once for each ID not yet known. */
function useTitles(ids: string[]): Record<string, string> {
    const { submit } = useHttp();
    const [, setKnown] = useState(0);
    const asked = useRef(new Set<string>());

    useEffect(() => {
        const wanted = ids.filter(
            (id) => !postTitles.has(id) && !asked.current.has(id),
        );

        if (wanted.length === 0) {
            return;
        }

        wanted.forEach((id) => asked.current.add(id));

        void (
            submit(
                LinkedContentController({ query: { ids: wanted } }),
            ) as Promise<Record<string, LinkedContent>>
        )
            .then((found) => {
                Object.entries(found).forEach(([id, state]) => {
                    if (state.title !== null) {
                        postTitles.set(id, state.title);
                    }
                });
                setKnown((current) => current + 1);
            })
            .catch(() => wanted.forEach((id) => asked.current.delete(id)));
    }, [ids, submit]);

    return Object.fromEntries(ids.map((id) => [id, postTitles.get(id) ?? '']));
}

/** Latest or Chosen, in the card's toolbar and its panel alike. */
function SourceSwitch({
    value,
    onChange,
    describedBy,
}: {
    value: string;
    onChange: (value: string) => void;
    describedBy?: string;
}) {
    return (
        <Segmented
            value={value}
            onValueChange={onChange}
            aria-label={__('Which posts')}
            aria-describedby={describedBy}
        >
            <SegmentedItem value="latest">{__('Latest')}</SegmentedItem>
            <SegmentedItem value="chosen">{__('Chosen')}</SegmentedItem>
        </Segmented>
    );
}

/**
 * Featured posts: a short list in a theme's region, the latest of a type
 * (optionally filed under one term) or posts chosen by hand. Stored as IDs
 * and a count; the site draws the list when the page is drawn.
 */
export function FeaturedPostsCard({
    node,
    selected,
    updateAttributes,
    deleteNode,
}: ReactNodeViewProps) {
    const attrs = read(node.attrs);
    const types = useListTypes();
    const titles = useTitles(attrs.source === 'chosen' ? attrs.items : []);
    const plural = (
        types.find((type) => type.value === attrs.type)?.plural ?? __('Posts')
    ).toLowerCase();
    const term =
        attrs.termId === null ? null : (termNames.get(attrs.termId) ?? null);

    const lists =
        attrs.source === 'chosen'
            ? __n(':count chosen post|:count chosen posts', attrs.items.length)
            : attrs.termId === null
              ? __('Latest :count :items', {
                    count: attrs.count,
                    items: plural,
                })
              : term === null
                ? __('Latest :count :items, filed under one category or tag', {
                      count: attrs.count,
                      items: plural,
                  })
                : __('Latest :count :items in :term', {
                      count: attrs.count,
                      items: plural,
                      term,
                  });

    return (
        <CardFrame
            label={__('Featured posts')}
            selected={selected}
            onRemove={deleteNode}
            defaultOpen={attrs.source === 'chosen' && attrs.items.length === 0}
            warning={
                attrs.source === 'chosen' && attrs.items.length === 0
                    ? { label: __('Choose up to 12 posts') }
                    : null
            }
            preview={attrs.heading}
            tools={
                // A phone's card head has no room beside the card's own buttons; the panel has the same switch.
                <div className="max-sm:hidden">
                    <SourceSwitch
                        value={attrs.source}
                        onChange={(source) => updateAttributes({ source })}
                    />
                </div>
            }
            settings={
                <FeaturedPostsSettings
                    attrs={node.attrs}
                    update={updateAttributes}
                />
            }
        >
            <div className="grid min-w-0 gap-1">
                <p className="text-ink font-semibold">
                    {attrs.heading.trim() === ''
                        ? __('Featured posts')
                        : attrs.heading}
                </p>
                <p className="text-ink-2 text-small">{lists}</p>
                {attrs.source === 'chosen' && attrs.items.length > 0 && (
                    <ol className="text-small text-ink grid list-decimal gap-0.5 ps-5">
                        {attrs.items.map((id) => (
                            <li key={id} className="truncate">
                                {shownTitle(titles[id] ?? '')}
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </CardFrame>
    );
}

/** The terms whose names match the query, from every taxonomy, while `enabled` is true. */
function useFoundTerms(query: string, enabled: boolean): FoundTerm[] {
    const { submit } = useHttp();
    const taxonomies = usePage().props.taxonomies;
    const [found, setFound] = useState<FoundTerm[]>([]);

    useEffect(() => {
        if (!enabled || query.trim() === '') {
            setFound([]);

            return;
        }

        const timer = window.setTimeout(() => {
            void Promise.all(
                taxonomies.map((taxonomy) =>
                    (
                        submit(
                            searchTerms({
                                query: { taxonomy: taxonomy.key, q: query },
                            }),
                        ) as Promise<{ id: string; name: string }[]>
                    )
                        .then((terms) =>
                            terms.map((term) => ({
                                id: term.id,
                                name: term.name,
                                taxonomy: taxonomy.key,
                            })),
                        )
                        .catch((): FoundTerm[] => []),
                ),
            ).then((lists) => {
                const all = lists.flat();
                all.forEach((term) => termNames.set(term.id, term.name));
                setFound(all);
            });
        }, 200);

        return () => window.clearTimeout(timer);
    }, [enabled, query, submit, taxonomies]);

    return found;
}

/** A featured posts card's settings, in the card's panel or the rail's inspector. */
export function FeaturedPostsSettings({
    attrs: raw,
    update: updateAttributes,
}: CardSettingsProps) {
    const attrs = read(raw);
    const id = useId();
    const types = useListTypes();
    const taxonomies = usePage().props.taxonomies;
    const [termQuery, setTermQuery] = useState('');
    const terms = useFoundTerms(termQuery, attrs.source === 'latest');
    const [postQuery, setPostQuery] = useState('');
    const [searching, setSearching] = useState(false);
    const targets = useLinkTargets(postQuery, searching).filter(
        (target) =>
            target.status === 'published' && !attrs.items.includes(target.id),
    );
    const titles = useTitles(attrs.source === 'chosen' ? attrs.items : []);
    const kind = (taxonomy: string): string =>
        taxonomies.find((entry) => entry.key === taxonomy)?.singular ?? '';
    const chosenTerm =
        attrs.termId === null
            ? null
            : (terms.find((term) => term.id === attrs.termId) ?? {
                  id: attrs.termId,
                  name: termNames.get(attrs.termId) ?? '',
                  taxonomy: '',
              });
    const termChoices =
        chosenTerm === null || terms.some((term) => term.id === chosenTerm.id)
            ? terms
            : [chosenTerm, ...terms];

    const move = (from: number, to: number): void => {
        const items = [...attrs.items];
        const [moved] = items.splice(from, 1);
        items.splice(to, 0, moved);
        updateAttributes({ items });
    };

    return (
        <>
            <TextSetting
                label={__('Heading (optional)')}
                hint={__(
                    'Shown above the list. Leave it empty and the theme shows none.',
                )}
                value={attrs.heading}
                onChange={(next) =>
                    updateAttributes({ heading: next === '' ? null : next })
                }
            />
            <div className="grid gap-2">
                <Label id={`${id}-which`}>{__('Which posts')}</Label>
                <div>
                    <SourceSwitch
                        value={attrs.source}
                        onChange={(source) => updateAttributes({ source })}
                        describedBy={`${id}-which-hint`}
                    />
                </div>
                <p id={`${id}-which-hint`} className="text-small text-ink-3">
                    {attrs.source === 'chosen'
                        ? __('Posts you pick, in the order you set.')
                        : __(
                              'The newest published items, kept up to date by themselves.',
                          )}
                </p>
            </div>
            {attrs.source === 'latest' ? (
                <>
                    <CardField label={__('Type')}>
                        {(props) => (
                            <SelectField
                                {...props}
                                value={attrs.type}
                                onValueChange={(type) =>
                                    updateAttributes({ type })
                                }
                                options={types}
                                className="h-8.5 w-full max-w-48"
                            />
                        )}
                    </CardField>
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-term-search`}>
                            {__('Filed under')}
                        </Label>
                        <Input
                            id={`${id}-term-search`}
                            type="search"
                            value={termQuery}
                            placeholder={__('Search categories and tags')}
                            onChange={(event) =>
                                setTermQuery(event.target.value)
                            }
                            className="text-small h-8.5"
                        />
                        <RadioGroup
                            aria-label={__('Filed under')}
                            value={attrs.termId ?? ''}
                            onValueChange={(next) =>
                                updateAttributes({
                                    termId: next === '' ? null : next,
                                })
                            }
                        >
                            <div className="flex min-w-0 items-center gap-2">
                                <RadioGroupItem
                                    id={`${id}-term-any`}
                                    value=""
                                />
                                <Label
                                    htmlFor={`${id}-term-any`}
                                    className="font-normal"
                                >
                                    {__('Anything: no category or tag')}
                                </Label>
                            </div>
                            {termChoices.map((term) => (
                                <div
                                    key={term.id}
                                    className="flex min-w-0 items-center gap-2"
                                >
                                    <RadioGroupItem
                                        id={`${id}-term-${term.id}`}
                                        value={term.id}
                                    />
                                    <Label
                                        htmlFor={`${id}-term-${term.id}`}
                                        className="min-w-0 flex-1 truncate font-normal"
                                    >
                                        {term.name === ''
                                            ? __('The chosen category or tag')
                                            : term.name}
                                    </Label>
                                    <span className="text-small text-ink-3 shrink-0">
                                        {kind(term.taxonomy)}
                                    </span>
                                </div>
                            ))}
                        </RadioGroup>
                    </div>
                    <CardField label={__('How many')} hint={__('1 to 12.')}>
                        {(props) => (
                            <Input
                                {...props}
                                type="number"
                                min={1}
                                max={MOST}
                                value={attrs.count}
                                onChange={(event) => {
                                    const count = Number(event.target.value);

                                    if (Number.isInteger(count)) {
                                        updateAttributes({
                                            count: Math.max(
                                                1,
                                                Math.min(MOST, count),
                                            ),
                                        });
                                    }
                                }}
                                className="text-small h-8.5 w-24"
                            />
                        )}
                    </CardField>
                </>
            ) : (
                <div className="grid gap-2">
                    <Label id={`${id}-chosen`}>{__('Posts, in order')}</Label>
                    {attrs.items.length > 0 && (
                        <ol
                            aria-labelledby={`${id}-chosen`}
                            className="border-line grid rounded-md border"
                        >
                            {attrs.items.map((itemId, index) => {
                                const title = shownTitle(titles[itemId] ?? '');

                                return (
                                    <li
                                        key={itemId}
                                        className="border-line flex min-w-0 items-center gap-1 border-b px-2 py-1 last:border-b-0"
                                    >
                                        <span className="text-small text-ink-3 w-5 shrink-0">
                                            {index + 1}
                                        </span>
                                        <span className="text-small text-ink min-w-0 flex-1 truncate">
                                            {title}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-xs"
                                            aria-label={__('Move :title up', {
                                                title,
                                            })}
                                            disabled={index === 0}
                                            onClick={() =>
                                                move(index, index - 1)
                                            }
                                        >
                                            <ArrowUp />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-xs"
                                            aria-label={__('Move :title down', {
                                                title,
                                            })}
                                            disabled={
                                                index === attrs.items.length - 1
                                            }
                                            onClick={() =>
                                                move(index, index + 1)
                                            }
                                        >
                                            <ArrowDown />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-xs"
                                            aria-label={__('Remove :title', {
                                                title,
                                            })}
                                            onClick={() =>
                                                updateAttributes({
                                                    items: attrs.items.filter(
                                                        (other) =>
                                                            other !== itemId,
                                                    ),
                                                })
                                            }
                                        >
                                            <X />
                                        </Button>
                                    </li>
                                );
                            })}
                        </ol>
                    )}
                    <Input
                        aria-label={__('Search published posts')}
                        aria-describedby={`${id}-chosen-hint`}
                        type="search"
                        value={postQuery}
                        placeholder={__('Search published posts')}
                        disabled={attrs.items.length >= MOST}
                        onFocus={() => setSearching(true)}
                        onChange={(event) => setPostQuery(event.target.value)}
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
                                                postTitles.set(
                                                    target.id,
                                                    target.title,
                                                );
                                                updateAttributes({
                                                    items: [
                                                        ...attrs.items,
                                                        target.id,
                                                    ].slice(0, MOST),
                                                });
                                                setPostQuery('');
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
                    <p
                        id={`${id}-chosen-hint`}
                        className="text-small text-ink-3"
                    >
                        {__(
                            ':count of at most 12. Readers see them in this order; a post that is unpublished drops out.',
                            { count: attrs.items.length },
                        )}
                    </p>
                </div>
            )}
            <div className="flex items-center gap-2">
                <Switch
                    id={`${id}-images`}
                    checked={attrs.images}
                    onCheckedChange={(images) => updateAttributes({ images })}
                />
                <Label htmlFor={`${id}-images`} className="font-normal">
                    {__('Show images')}
                </Label>
            </div>
        </>
    );
}
