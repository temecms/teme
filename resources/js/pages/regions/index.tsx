import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import RegionsController from '@/actions/App/Http/Admin/RegionsController';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { ListPage } from '@/components/patterns/list-page';
import { RegionPanel } from '@/components/regions/region-panel';
import {
    changesRegion,
    chooseRegion,
    HOVER_MS,
    prefetchRegion,
} from '@/components/regions/region-visits';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusPill } from '@/components/ui/status-pill';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { TermChips } from '@/components/ui/term-chips';
import { useSectionCrumb } from '@/lib/crumbs';
import { formatDateTime } from '@/lib/dates';
import { __, __n } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as regionsIndex } from '@/routes/regions';
import { index as themesIndex } from '@/routes/themes';
import type {
    ChosenRegion,
    OtherRegionRow,
    RegionState,
    RegionsWorkspacePage,
    WorkspaceRegion,
} from '@/types';

/** A region's state as a pill: Published, with changes waiting, or Draft; Empty in quiet words. */
function StatePill({ state }: { state: RegionState }) {
    switch (state) {
        case 'empty':
            return <span className="text-ink-3">{__('Empty')}</span>;
        case 'draft':
            return <StatusPill tone="neutral">{__('Draft')}</StatusPill>;
        case 'published':
            return <StatusPill tone="ok">{__('Published')}</StatusPill>;
        case 'changed':
            return (
                <StatusPill tone="warn">
                    {__('Published, with unpublished changes')}
                </StatusPill>
            );
    }
}

function edited(at: string | null): string {
    return at === null ? '—' : formatDateTime(at);
}

/**
 * Fetches a region's visit ahead when the pointer rests on its row, or the
 * row takes focus or a touch, so choosing it shows it at once. The region
 * already shown is never fetched again.
 */
function useAhead(key: string, shown: boolean) {
    const timer = useRef<number | null>(null);
    const stop = () => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
            timer.current = null;
        }
    };

    useEffect(() => stop, []);

    const now = () => {
        stop();

        if (!shown) {
            prefetchRegion(key);
        }
    };

    return {
        onMouseEnter: () => {
            stop();
            timer.current = window.setTimeout(now, HOVER_MS);
        },
        onMouseLeave: stop,
        onFocus: now,
        onTouchStart: now,
    };
}

/** One region's row: choosing it shows the region, and resting on it fetches it ahead. */
function RegionRow({
    region,
    shown,
}: {
    region: WorkspaceRegion;
    shown: boolean;
}) {
    const ahead = useAhead(region.key, shown);

    return (
        <Button
            type="button"
            variant="ghost"
            data-test="region-choice"
            data-region={region.key}
            aria-current={shown ? 'page' : undefined}
            onClick={() => chooseRegion(region.key)}
            {...ahead}
            className={cn(
                'w-full min-w-0 justify-start gap-2 px-2 text-left',
                shown && 'bg-surface-3 text-ink font-semibold',
            )}
        >
            <span className="text-ink min-w-0 flex-1 truncate">
                {region.label}
            </span>
            {region.dirty && (
                <span
                    className="bg-warn size-2 shrink-0 rounded-full"
                    title={__('Changes not published yet')}
                >
                    <span className="sr-only">
                        {__('Changes not published yet')}
                    </span>
                </span>
            )}
            <span className="text-small text-ink-2 shrink-0 tabular-nums">
                {__(':used/:max', {
                    used: region.used,
                    max: region.max,
                })}
            </span>
        </Button>
    );
}

/** The theme's regions in their groups, each with how full it is and whether it has changes not yet published. */
function RegionList({
    groups,
    chosen,
}: {
    groups: RegionsWorkspacePage['groups'];
    chosen: string | null;
}) {
    const [search, setSearch] = useState('');
    const found = (region: WorkspaceRegion) =>
        region.label.toLowerCase().includes(search.trim().toLowerCase());
    const many = groups.reduce((all, group) => all + group.regions.length, 0);

    return (
        <nav aria-label={__('Regions')} className="grid content-start gap-4">
            {many > 6 && (
                <Input
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder={__('Find a region')}
                    aria-label={__('Find a region')}
                />
            )}
            {groups.map((group) => {
                const shown = group.regions.filter(found);

                return shown.length === 0 ? null : (
                    <section key={group.group} className="grid gap-1">
                        <h2 className="text-caption text-ink-3 px-2 font-semibold uppercase">
                            {group.group}
                        </h2>
                        <ul className="grid gap-0.5">
                            {shown.map((region) => (
                                <li key={region.key}>
                                    <RegionRow
                                        region={region}
                                        shown={region.key === chosen}
                                    />
                                </li>
                            ))}
                        </ul>
                    </section>
                );
            })}
        </nav>
    );
}

/** The chosen region: its name and state, then its blocks, or a way to fill it the first time. */
function ChosenPanel({
    region,
    page,
}: {
    region: ChosenRegion;
    page: RegionsWorkspacePage;
}) {
    return (
        <section
            aria-labelledby="chosen-region"
            className="border-line grid content-start gap-4 rounded-lg border p-4"
        >
            <div className="flex flex-wrap items-center gap-2">
                <h2 id="chosen-region" className="text-lead font-semibold">
                    {region.label}
                </h2>
                <StatePill state={region.state} />
            </div>
            {!region.every && region.cardLabels.length > 0 && (
                <div data-test="region-cards" className="min-w-0">
                    <TermChips
                        terms={region.cardLabels.map((label) => ({
                            id: label,
                            name: label,
                        }))}
                        noun={{ one: __('card'), many: __('cards') }}
                        emptyLabel={__('No cards')}
                    />
                </div>
            )}
            {region.session === null || region.contentId === null ? (
                <div className="grid justify-items-start gap-3">
                    <p className="text-ink-2">
                        {__(
                            'Nothing is here yet, so the theme draws nothing in this spot. :limit.',
                            { limit: region.limit },
                        )}
                    </p>
                    {page.theme !== null && (
                        <Button
                            type="button"
                            data-test="region-create"
                            onClick={() =>
                                router.post(
                                    RegionsController.store.url(),
                                    {
                                        theme: page.theme?.code ?? '',
                                        region: region.key,
                                    },
                                    changesRegion(region.key),
                                )
                            }
                        >
                            {__('Fill this region')}
                        </Button>
                    )}
                </div>
            ) : (
                <RegionPanel
                    // A discard gives the region another working revision: the panel starts again from it.
                    key={`${region.contentId}:${region.session.workingRevisionId ?? ''}`}
                    region={region}
                    session={region.session}
                    page={page}
                />
            )}
        </section>
    );
}

/**
 * Content › Regions (I-395): the site's own content in the spots the theme
 * sets aside, such as a box after each article, on one screen. The regions
 * are listed in their groups; the chosen one is edited in place, card by
 * card, and published. Regions kept from themes not in use are listed
 * openly under Other themes, read-only, never folded away.
 */
export default function RegionsIndex(page: RegionsWorkspacePage) {
    const { theme, groups, chosen, others } = page;
    const title = __('Regions');
    const contentCrumb = useSectionCrumb('content');
    const count = groups.reduce((all, group) => all + group.regions.length, 0);

    setLayoutProps({
        breadcrumbs: [contentCrumb, { title, href: regionsIndex() }],
    });

    const otherColumns: Column<OtherRegionRow>[] = [
        {
            id: 'label',
            owner: 'core',
            header: 'Region',
            cell: (row) => <span className="font-semibold">{row.label}</span>,
        },
        {
            id: 'theme',
            owner: 'core',
            header: 'Theme',
            cell: (row) => (
                <span className="grid gap-0.5">
                    {row.themeName ?? (
                        <span className="text-ink-2">
                            Theme no longer installed
                        </span>
                    )}
                    {row.themeCards > 0 && (
                        <span className="text-ink-2 text-small">
                            {__n(
                                ':count theme card kept|:count theme cards kept',
                                row.themeCards,
                            )}
                        </span>
                    )}
                </span>
            ),
        },
        {
            id: 'state',
            owner: 'core',
            header: __('State'),
            cell: (row) => <StatePill state={row.state} />,
        },
        {
            id: 'edited',
            owner: 'core',
            header: 'Last edited',
            cell: (row) => (
                <span className="text-ink-2">{edited(row.editedAt)}</span>
            ),
        },
    ];

    return (
        <ListPage
            title={title}
            description={
                theme === null
                    ? 'Your own content in the spots the active theme sets aside, such as a box after each article.'
                    : `Your own content in the spots the active theme, ${theme.name}, sets aside, such as a box after each article. Regions come from whichever theme is active: switch themes and you fill that theme’s regions instead. Each region takes only the cards its theme allows.`
            }
        >
            <Head title={title} />

            {chosen === null && (
                <EmptyState
                    kind="nothing-yet"
                    title={
                        theme === null
                            ? 'No theme is in use'
                            : `${theme.name} has no regions`
                    }
                    description={
                        theme === null
                            ? undefined
                            : `There’s nothing to fill here while ${theme.name} is the active theme.`
                    }
                />
            )}

            <Tabs defaultValue={chosen === null ? 'others' : 'active'}>
                <TabsList>
                    <TabsTrigger value="active">
                        {theme?.name ?? __('Active theme')}{' '}
                        <span className="text-ink-2 tabular-nums">{count}</span>
                    </TabsTrigger>
                    <TabsTrigger value="others" data-test="others-tab">
                        {__('Other themes')}{' '}
                        <span className="text-ink-2 tabular-nums">
                            {others.length}
                        </span>
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="active">
                    {chosen !== null && (
                        <div className="grid gap-6 lg:flex lg:items-start">
                            <div className="lg:w-(--side-w) lg:shrink-0">
                                <RegionList
                                    groups={groups}
                                    chosen={chosen.key}
                                />
                            </div>
                            <div className="grid min-w-0 content-start gap-3 lg:flex-1">
                                <ChosenPanel region={chosen} page={page} />
                            </div>
                        </div>
                    )}
                </TabsContent>

                <TabsContent value="others">
                    <section
                        aria-labelledby="unplaced-heading"
                        data-test="unplaced"
                        className="grid gap-3"
                    >
                        <div className="grid gap-1">
                            <h2
                                id="unplaced-heading"
                                className="text-lead font-semibold"
                            >
                                From other themes{' '}
                                <span className="text-ink-2 tabular-nums">
                                    {others.length}
                                </span>
                            </h2>
                            <p className="text-ink-2 text-body">
                                {
                                    'Kept as they were from themes you aren’t using. Each shows again if you switch back to its theme.'
                                }{' '}
                                <Link
                                    href={themesIndex().url}
                                    className="text-accent underline"
                                >
                                    {__('Open Themes')}
                                </Link>
                            </p>
                        </div>
                        <DataTable
                            columns={otherColumns}
                            rows={others}
                            rowKey={(row) => row.slug}
                            caption="Regions kept from themes not in use"
                            empty={
                                <EmptyState
                                    kind="nothing-yet"
                                    title={__('Nothing kept from other themes')}
                                />
                            }
                        />
                    </section>
                </TabsContent>
            </Tabs>
        </ListPage>
    );
}
