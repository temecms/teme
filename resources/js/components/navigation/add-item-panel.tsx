import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { useLinkTargets } from '@/components/editor/use-link-targets';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { __ } from '@/lib/i18n';
import { search as searchTerms } from '@/routes/terms';
import type { MenuItem, MenuTargetKind } from '@/types';
import { shownTitle, quotedTitle } from '@/lib/titles';

type NewItem = Omit<MenuItem, 'children'>;

type Tab = 'home' | 'heading' | 'content' | 'terms' | 'address' | 'languages';

type FoundTerm = { id: string; name: string; path: string };

/** An address SaveNavigationItems takes: http(s), mailto, tel, or a path on this site, never //another-site. */
const ADDRESS = /^(https?:\/\/[^\s/?#]+\S*|mailto:\S+|tel:\S+|\/(?!\/)\S*)$/i;

/** What a heading is, said where one is added. */
const HEADING_HINT =
    'A label that leads nowhere and opens the items under it, such as “Services” over your service pages.';

function Results({
    groups,
}: {
    groups: {
        label: string;
        items: { id: string; title: string; add: () => void }[];
    }[];
}) {
    return (
        <div className="grid gap-4">
            {groups.map((group) => (
                <section key={group.label} className="grid gap-1">
                    <h3 className="text-ink-2 text-caption font-semibold">
                        {group.label}
                    </h3>
                    <ul className="grid">
                        {group.items.map((item) => (
                            <li key={item.id}>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="text-ink w-full justify-start"
                                    onClick={item.add}
                                >
                                    {item.title}
                                </Button>
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
        </div>
    );
}

/**
 * The side panel for adding an item: the home page; a heading, which leads
 * nowhere and opens the items put under it; a page or post, found with the
 * editor's own content picker; a category or tag, one group per public
 * taxonomy; or a web address. Only public types and taxonomies are offered:
 * nothing else has an address.
 */
export function AddItemPanel({
    open,
    onOpenChange,
    types,
    taxonomies,
    onAdd,
    multilingual = false,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    types: MenuTargetKind[];
    taxonomies: MenuTargetKind[];
    onAdd: (item: NewItem) => void;
    /** Whether the site has other languages, so a language switcher is offered. */
    multilingual?: boolean;
}) {
    const [tab, setTab] = useState<Tab>('content');
    const [contentQuery, setContentQuery] = useState('');
    const [termQuery, setTermQuery] = useState('');
    const [terms, setTerms] = useState<Record<string, FoundTerm[]>>({});
    const [url, setUrl] = useState('');
    const [label, setLabel] = useState('');
    const [urlError, setUrlError] = useState<string | undefined>();
    const [heading, setHeading] = useState('');
    const [headingError, setHeadingError] = useState<string | undefined>();
    const [added, setAdded] = useState('');
    const { submit } = useHttp();

    const targets = useLinkTargets(contentQuery, open && tab === 'content');

    useEffect(() => {
        if (!open || tab !== 'terms' || termQuery.trim() === '') {
            setTerms({});

            return;
        }

        const timer = window.setTimeout(() => {
            void Promise.all(
                taxonomies.map(async (taxonomy) => [
                    taxonomy.key,
                    (await submit(
                        searchTerms({
                            query: { taxonomy: taxonomy.key, q: termQuery },
                        }),
                    )) as FoundTerm[],
                ]),
            ).then((found) => setTerms(Object.fromEntries(found)));
        }, 200);

        return () => window.clearTimeout(timer);
    }, [open, tab, termQuery, taxonomies, submit]);

    const tabs: { key: Tab; label: string }[] = [
        { key: 'home', label: 'Home page' },
        { key: 'heading', label: 'Heading' },
        {
            key: 'content',
            label: types.map((type) => type.plural).join(' and ') || 'Content',
        },
        {
            key: 'terms',
            label:
                taxonomies.map((taxonomy) => taxonomy.plural).join(' and ') ||
                'Terms',
        },
        { key: 'address', label: 'Web address' },
        ...(multilingual
            ? [{ key: 'languages' as const, label: __('Language switcher') }]
            : []),
    ];

    const onTabKey = (event: KeyboardEvent) => {
        const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];

        if (step === undefined) {
            return;
        }

        event.preventDefault();
        const next =
            tabs[
                (tabs.findIndex((item) => item.key === tab) +
                    step +
                    tabs.length) %
                    tabs.length
            ].key;
        setTab(next);
        document.getElementById(`add-item-tab-${next}`)?.focus();
    };

    const contentGroups = types
        .map((type) => ({
            label: type.plural,
            items: targets
                .filter((target) => target.type === type.key)
                .map((target) => ({
                    id: target.id,
                    title:
                        target.status === 'published'
                            ? shownTitle(target.title)
                            : `${shownTitle(target.title)} (${target.status})`,
                    add: () => {
                        onAdd({
                            kind: 'content',
                            targetId: target.id,
                            url: null,
                            label: null,
                            opensNewTab: false,
                            target: `${type.singular}: ${shownTitle(target.title)}`,
                            warning:
                                target.status === 'published'
                                    ? null
                                    : `Not shown: the ${type.singular.toLowerCase()} isn’t published`,
                        });
                        setAdded(`Added “${quotedTitle(target.title)}”.`);
                    },
                })),
        }))
        .filter((group) => group.items.length > 0);

    const termGroups = taxonomies
        .map((taxonomy) => ({
            label: taxonomy.plural,
            items: (terms[taxonomy.key] ?? []).map((term) => ({
                id: term.id,
                title: term.path,
                add: () => {
                    onAdd({
                        kind: 'term',
                        targetId: term.id,
                        url: null,
                        label: null,
                        opensNewTab: false,
                        target: `${taxonomy.singular}: ${term.name}`,
                        warning: null,
                    });
                    setAdded(`Added “${term.name}”.`);
                    setTermQuery('');
                },
            })),
        }))
        .filter((group) => group.items.length > 0);

    const addHome = () => {
        onAdd({
            kind: 'home',
            targetId: null,
            url: null,
            label: null,
            opensNewTab: false,
            target: 'Home',
            warning: null,
        });
        setAdded('Added “Home”.');
    };

    const addSwitcher = () => {
        onAdd({
            kind: 'languages',
            targetId: null,
            url: null,
            label: null,
            opensNewTab: false,
            target: __('Language switcher'),
            warning: null,
        });
        setAdded(__('Added the language switcher.'));
    };

    const addHeading = () => {
        const name = heading.trim();

        if (name === '') {
            setHeadingError('Give the heading a label.');

            return;
        }

        onAdd({
            kind: 'heading',
            targetId: null,
            url: null,
            label: name,
            opensNewTab: false,
            target: 'Heading',
            warning: null,
        });
        setAdded(`Added “${name}”. Move the items it opens under it.`);
        setHeading('');
        setHeadingError(undefined);
    };

    const addAddress = () => {
        const address = url.trim();

        if (!ADDRESS.test(address)) {
            setUrlError(
                'Use an address starting http://, https://, mailto:, tel: or a single /.',
            );

            return;
        }

        onAdd({
            kind: 'url',
            targetId: null,
            url: address,
            label: label.trim() || null,
            opensNewTab: false,
            target: address,
            warning: null,
        });
        setAdded(`Added “${label.trim() || address}”.`);
        setUrl('');
        setLabel('');
        setUrlError(undefined);
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent>
                <SheetHeader>
                    <SheetTitle>Add an item</SheetTitle>
                    <SheetDescription>
                        Items are added at the end of the menu. Move them into
                        place, then save.
                    </SheetDescription>
                </SheetHeader>

                <ScrollArea className="flex-1">
                    <div className="grid gap-4 px-4 pb-4">
                        <div
                            role="tablist"
                            aria-label="What to add"
                            className="flex flex-wrap gap-1"
                            onKeyDown={onTabKey}
                        >
                            {tabs.map((item) => (
                                <Button
                                    key={item.key}
                                    id={`add-item-tab-${item.key}`}
                                    type="button"
                                    role="tab"
                                    aria-selected={tab === item.key}
                                    aria-controls={`add-item-panel-${item.key}`}
                                    tabIndex={tab === item.key ? 0 : -1}
                                    onClick={() => setTab(item.key)}
                                    variant="ghost"
                                    size="sm"
                                    className="aria-selected:bg-surface-3 aria-selected:text-ink"
                                >
                                    {item.label}
                                </Button>
                            ))}
                        </div>

                        {tab === 'languages' && (
                            <div
                                id="add-item-panel-languages"
                                role="tabpanel"
                                aria-labelledby="add-item-tab-languages"
                                className="grid justify-items-start gap-3"
                            >
                                <p className="text-ink-2 text-small">
                                    {__(
                                        'A link to each language version of the page a reader is on. Pages with no other version show nothing.',
                                    )}
                                </p>
                                <Button
                                    type="button"
                                    data-test="add-language-switcher"
                                    onClick={addSwitcher}
                                >
                                    {__('Add the language switcher')}
                                </Button>
                            </div>
                        )}

                        {tab === 'home' && (
                            <div
                                id="add-item-panel-home"
                                role="tabpanel"
                                aria-labelledby="add-item-tab-home"
                                className="grid justify-items-start gap-3"
                            >
                                <p className="text-ink-2 text-small">
                                    A link to the front page of the site. The
                                    label starts as “Home”.
                                </p>
                                <Button type="button" onClick={addHome}>
                                    Add the home page
                                </Button>
                            </div>
                        )}

                        {tab === 'heading' && (
                            <form
                                id="add-item-panel-heading"
                                role="tabpanel"
                                aria-labelledby="add-item-tab-heading"
                                className="grid gap-3"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    addHeading();
                                }}
                            >
                                <p className="text-ink-2 text-small">
                                    {HEADING_HINT}
                                </p>
                                <div className="grid gap-2">
                                    <Label htmlFor="add-item-heading">
                                        Label
                                    </Label>
                                    <Input
                                        id="add-item-heading"
                                        value={heading}
                                        onChange={(event) =>
                                            setHeading(event.target.value)
                                        }
                                        maxLength={200}
                                        aria-describedby="add-item-heading-error"
                                        aria-invalid={
                                            headingError !== undefined
                                                ? true
                                                : undefined
                                        }
                                        required
                                    />
                                    <InputError
                                        id="add-item-heading-error"
                                        message={headingError}
                                    />
                                </div>
                                <div>
                                    <Button>Add heading</Button>
                                </div>
                            </form>
                        )}

                        {tab === 'content' && (
                            <div
                                id="add-item-panel-content"
                                role="tabpanel"
                                aria-labelledby="add-item-tab-content"
                                className="grid gap-3"
                            >
                                <Input
                                    value={contentQuery}
                                    onChange={(event) =>
                                        setContentQuery(event.target.value)
                                    }
                                    placeholder="Find by title"
                                    aria-label="Find by title"
                                />
                                {contentGroups.length === 0 ? (
                                    <p className="text-ink-2 text-small">
                                        Nothing found.
                                    </p>
                                ) : (
                                    <Results groups={contentGroups} />
                                )}
                            </div>
                        )}

                        {tab === 'terms' && (
                            <div
                                id="add-item-panel-terms"
                                role="tabpanel"
                                aria-labelledby="add-item-tab-terms"
                                className="grid gap-3"
                            >
                                <Input
                                    value={termQuery}
                                    onChange={(event) =>
                                        setTermQuery(event.target.value)
                                    }
                                    placeholder="Find by name"
                                    aria-label="Find by name"
                                />
                                {termQuery.trim() !== '' &&
                                    (termGroups.length === 0 ? (
                                        <p className="text-ink-2 text-small">
                                            Nothing found.
                                        </p>
                                    ) : (
                                        <Results groups={termGroups} />
                                    ))}
                            </div>
                        )}

                        {tab === 'address' && (
                            <form
                                id="add-item-panel-address"
                                role="tabpanel"
                                aria-labelledby="add-item-tab-address"
                                className="grid gap-3"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    addAddress();
                                }}
                            >
                                <div className="grid gap-2">
                                    <Label htmlFor="add-item-url">
                                        Address
                                    </Label>
                                    <Input
                                        id="add-item-url"
                                        value={url}
                                        onChange={(event) =>
                                            setUrl(event.target.value)
                                        }
                                        placeholder="https://example.org/"
                                        aria-describedby="add-item-url-error"
                                        aria-invalid={
                                            urlError !== undefined
                                                ? true
                                                : undefined
                                        }
                                        required
                                    />
                                    <InputError
                                        id="add-item-url-error"
                                        message={urlError}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="add-item-label">
                                        Label
                                    </Label>
                                    <Input
                                        id="add-item-label"
                                        value={label}
                                        onChange={(event) =>
                                            setLabel(event.target.value)
                                        }
                                        maxLength={200}
                                    />
                                </div>
                                <div>
                                    <Button>Add</Button>
                                </div>
                            </form>
                        )}

                        <p role="status" className="sr-only">
                            {added}
                        </p>
                    </div>
                </ScrollArea>
            </SheetContent>
        </Sheet>
    );
}
