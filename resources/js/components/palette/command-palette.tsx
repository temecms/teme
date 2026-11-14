import { router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { navIcon } from '@/lib/nav-icons';
import { cn } from '@/lib/utils';
import {
    aiCommands,
    doCommands,
    goToCommands,
    goToTargets,
    matches,
} from './palette-commands';
import type { PaletteCommand, PaletteContext } from './palette-context';
import {
    usePaletteSearch,
    usePaletteShortcut,
    useScreenCommands,
} from './use-palette';
import type { PaletteResult, PaletteResults } from './use-palette';
import { RECENT_SHOWN, recentHrefs, rememberVisit } from './recent';
import { shownTitle } from '@/lib/titles';

/** The search's groups, in the order they are shown, with their names. */
const GROUPS: {
    key: Exclude<keyof PaletteResults, 'textSearch'>;
    label: string;
}[] = [
    { key: 'content', label: 'Content' },
    { key: 'media', label: 'Media' },
    { key: 'terms', label: 'Terms' },
    { key: 'users', label: 'People' },
    { key: 'settings', label: 'Settings' },
];

/** What each result is, in words, beside it. */
const KIND: Record<PaletteResult['kind'], string> = {
    content: 'Content',
    media: 'Media',
    term: 'Term',
    user: 'Person',
    setting: 'Setting',
};

/** Each kind of result's icon (I-220). */
const KIND_ICON: Record<PaletteResult['kind'], string> = {
    content: 'file-text',
    media: 'image',
    term: 'tag',
    user: 'users',
    setting: 'settings',
};

type Row = {
    id: string;
    label: string;
    detail: string;
    kind: string;
    icon: string;
    run: () => void;
};

type Section = { label: string; rows: Row[] };

/**
 * The command palette (I-103, I-220): ⌘K or Ctrl K anywhere in the admin.
 * With a query it shows what the search found, grouped and counted; then
 * Go to — the menu, from the same registry the sidebar draws — and Do: new
 * items, uploads, and the commands the open screen registers. An empty query
 * asks the server nothing.
 */
export function CommandPalette() {
    const page = usePage();
    const { nav, contentTypes, auth } = page.props;
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const listRef = useRef<HTMLDivElement>(null);
    const { results, searching } = usePaletteSearch(open ? query : '');
    const screen = useScreenCommands();
    const { currentUrl } = useCurrentUrl();
    const targets = useMemo(() => goToTargets(nav), [nav]);

    // The screen this page is, remembered when Go to offers it and it isn't
    // Home: never an item's title (I-220).
    useEffect(() => {
        if (
            targets.some(
                (target) =>
                    target.href === currentUrl && target.id !== 'go.dashboard',
            )
        ) {
            rememberVisit(auth.user.id, currentUrl);
        }
    }, [targets, currentUrl, auth.user.id]);

    const toggle = useCallback(() => setOpen((was) => !was), []);
    usePaletteShortcut(toggle);

    // Others ask for it too: the header's search button.
    useEffect(() => {
        const show = () => setOpen(true);
        window.addEventListener('teme:palette', show);

        return () => window.removeEventListener('teme:palette', show);
    }, []);

    const context = useMemo<PaletteContext>(() => {
        const props = page.props as Record<string, unknown>;
        const can = (props.can ?? {}) as Record<string, unknown>;
        const session = props.session as { id?: string } | undefined;

        return {
            route: page.component.replaceAll('/', '.'),
            contentId:
                page.component === 'content/edit'
                    ? (session?.id ?? null)
                    : null,
            aiTasks: (
                (props.ai as { tasks?: { key: string }[] } | null | undefined)
                    ?.tasks ?? []
            ).map((task) => task.key),
            can: (ability) =>
                ability === 'content.create'
                    ? contentTypes.some((type) => type.canCreate)
                    : typeof can[ability] === 'boolean'
                      ? (can[ability] as boolean)
                      : nav.some((entry) => entry.key === ability),
        };
    }, [page.component, page.props, contentTypes, nav]);

    const close = () => setOpen(false);

    const sections = useMemo<Section[]>(() => {
        const toRow = (command: PaletteCommand, icon: string): Row => ({
            id: command.id,
            label: command.label,
            detail: command.description ?? '',
            kind: command.group,
            icon: command.icon ?? icon,
            run: () => {
                close();
                command.run(context);
            },
        });

        const offered = (command: PaletteCommand) =>
            (command.when?.(context) ?? true) &&
            (command.permission === undefined ||
                context.can(command.permission)) &&
            matches(command, query);

        const byOrder = (a: PaletteCommand, b: PaletteCommand) =>
            a.order - b.order;

        const { textSearch } = results;
        // Titles are searched here; the text in the list, which this opens.
        const inText: Row[] = textSearch
            ? [
                  {
                      id: 'content.text-search',
                      label: 'Search in text →',
                      detail: `${textSearch.plural} whose published text has “${query.trim()}”`,
                      kind: 'Search',
                      icon: 'search',
                      run: () => {
                          close();
                          router.visit(textSearch.url);
                      },
                  },
              ]
            : [];

        // What they visited last, read as the palette opens with nothing
        // typed: only screens Go to still offers, newest first, never the
        // one they're on (I-220).
        const recent: Row[] =
            open && query.trim() === ''
                ? recentHrefs(auth.user.id)
                      .filter((href) => href !== currentUrl)
                      .flatMap((href) => {
                          // The most particular screen at that address: Settings › General, not Settings.
                          const target = targets.findLast(
                              (candidate) => candidate.href === href,
                          );

                          return target === undefined ||
                              target.id === 'go.dashboard'
                              ? []
                              : [target];
                      })
                      .slice(0, RECENT_SHOWN)
                      .map((target) => ({
                          id: `recent.${target.id}`,
                          label: target.label,
                          detail: '',
                          kind: 'Go to',
                          icon: target.icon,
                          run: () => {
                              close();
                              router.visit(target.href);
                          },
                      }))
                : [];

        const found: Section[] = GROUPS.map(({ key, label }) => ({
            label,
            rows: [
                ...results[key].map((result) => ({
                    id: `${result.kind}.${result.id}`,
                    label: shownTitle(result.title),
                    detail: result.subtitle,
                    kind:
                        result.kind === 'content'
                            ? result.canEdit
                                ? 'Edit'
                                : 'Show in list'
                            : KIND[result.kind],
                    icon: KIND_ICON[result.kind],
                    run: () => {
                        close();
                        router.visit(result.url);
                    },
                })),
                ...(key === 'content' ? inText : []),
            ],
        }));

        return [
            { label: 'Recent', rows: recent },
            ...found,
            {
                label: 'Go to',
                rows: goToCommands(nav)
                    .filter(offered)
                    .sort(byOrder)
                    .map((command) => toRow(command, 'circle')),
            },
            {
                label: 'Do',
                rows: [...doCommands(contentTypes, nav), ...screen.flat()]
                    .filter(offered)
                    .sort(byOrder)
                    .map((command) => toRow(command, 'circle')),
            },
            {
                label: 'AI',
                rows: aiCommands(screen)
                    .filter(offered)
                    .sort(byOrder)
                    .map((command) => toRow(command, 'sparkles')),
            },
        ].filter((section) => section.rows.length > 0);
    }, [
        results,
        nav,
        contentTypes,
        screen,
        context,
        query,
        open,
        targets,
        currentUrl,
        auth.user.id,
    ]);

    const rows = sections.flatMap((section) => section.rows);

    // A new query starts at the top.
    useEffect(() => setActive(0), [query, results]);

    useEffect(() => {
        listRef.current
            ?.querySelector(`[data-index="${active}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    const keys = (event: React.KeyboardEvent) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((index) => Math.min(index + 1, rows.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => Math.max(index - 1, 0));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            rows[active]?.run();
        }
    };

    let index = -1;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (!next) {
                    setQuery('');
                }
            }}
        >
            <DialogContent
                className="top-[15%] max-h-[70vh] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-[640px]"
                onKeyDown={keys}
            >
                <DialogTitle className="sr-only">Command palette</DialogTitle>
                <DialogDescription className="sr-only">
                    Search content, media, terms, people and settings, go to a
                    screen, or run a command. Use the arrow keys and Enter.
                </DialogDescription>
                <div className="border-border flex items-center gap-2 border-b pr-10 pl-3">
                    <Search className="text-muted-foreground size-4 shrink-0" />
                    <input
                        autoFocus
                        role="combobox"
                        aria-expanded="true"
                        aria-controls="palette-list"
                        aria-activedescendant={
                            rows[active] ? `palette-row-${active}` : undefined
                        }
                        aria-label="Search, go to, or do"
                        placeholder="Search, go to, or do…"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        className="h-11 w-full bg-transparent text-[14px] outline-none"
                    />
                </div>
                <div
                    id="palette-list"
                    ref={listRef}
                    role="listbox"
                    aria-label="Results"
                    className="max-h-[calc(70vh-44px)] overflow-y-auto py-1"
                >
                    {sections.map((section) => (
                        <div
                            key={section.label}
                            role="group"
                            aria-label={section.label}
                        >
                            <p className="text-muted-foreground px-3 pt-2 pb-1 text-[11px] font-medium">
                                {section.label}{' '}
                                <span className="tabular-nums">
                                    ({section.rows.length})
                                </span>
                            </p>
                            {section.rows.map((row) => {
                                index += 1;
                                const position = index;
                                const Icon = navIcon(row.icon);

                                return (
                                    <div
                                        key={row.id}
                                        id={`palette-row-${position}`}
                                        data-index={position}
                                        role="option"
                                        aria-selected={position === active}
                                        onMouseMove={() => setActive(position)}
                                        onClick={row.run}
                                        className={cn(
                                            'mx-1 flex cursor-pointer items-center gap-3 rounded-sm px-2 py-1.5 text-[13px]',
                                            position === active && 'bg-muted',
                                        )}
                                    >
                                        <Icon
                                            aria-hidden="true"
                                            className="text-muted-foreground size-4 shrink-0"
                                        />
                                        <span className="grid min-w-0 flex-1">
                                            <span
                                                className="truncate"
                                                data-test="palette-label"
                                            >
                                                {row.label}
                                            </span>
                                            {row.detail !== '' && (
                                                <span className="text-muted-foreground truncate text-xs">
                                                    {row.detail}
                                                </span>
                                            )}
                                        </span>
                                        <span className="text-muted-foreground shrink-0 text-[11px]">
                                            {row.kind}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    ))}
                    {rows.length === 0 && (
                        <p className="text-muted-foreground px-3 py-6 text-center text-[13px]">
                            {searching ? 'Searching…' : 'Nothing matches.'}
                        </p>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
