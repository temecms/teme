import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import RegionsController from '@/actions/App/Http/Admin/RegionsController';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { ListPage } from '@/components/patterns/list-page';
import { RegionPanel } from '@/components/regions/region-panel';
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

/** Shows another region, keeping the page as it is around it. */
function choose(key: string): void {
    router.get(
        regionsIndex({ query: { region: key } }).url,
        {},
        { preserveScroll: true, preserveState: true, only: ['chosen'] },
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
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        data-test="region-choice"
                                        data-region={region.key}
                                        aria-current={
                                            region.key === chosen
                                                ? 'page'
                                                : undefined
                                        }
                                        onClick={() => choose(region.key)}
                                        className={cn(
                                            'w-full min-w-0 justify-start gap-2 px-2 text-left',
                                            region.key === chosen &&
                                                'bg-accent-soft text-on-accent-soft font-semibold',
                                        )}
                                    >
                                        <span className="min-w-0 flex-1 truncate">
                                            {region.label}
                                        </span>
                                        {region.dirty && (
                                            <span
                                                className="bg-warn size-2 shrink-0 rounded-full"
                                                title={__(
                                                    'Changes not published yet',
                                                )}
                                            >
                                                <span className="sr-only">
                                                    {__(
                                                        'Changes not published yet',
                                                    )}
                                                </span>
                                            </span>
                                        )}
                                        <span
                                            className={cn(
                                                'text-small shrink-0 tabular-nums',
                                                region.key === chosen
                                                    ? 'text-on-accent-soft'
                                                    : 'text-ink-2',
                                            )}
                                        >
                                            {__(':used of :max slots', {
                                                used: region.used,
                                                max: region.max,
                                            })}
                                        </span>
                                    </Button>
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
            className="bg-surface shadow-raised grid content-start gap-4 rounded-lg p-4"
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
                                router.post(RegionsController.store.url(), {
                                    theme: page.theme?.code ?? '',
                                    region: region.key,
                                })
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
                    ? __(
                          'Your own content in the spots the active theme sets aside, such as a box after each article.',
                      )
                    : __(
                          'Your own content in the spots the active theme, :theme, sets aside, such as a box after each article. Regions come from whichever theme is active: switch themes and you fill that theme’s regions instead. Each region takes only the cards its theme allows.',
                          { theme: theme.name },
                      )
            }
        >
            <Head title={title} />

            {chosen === null && (
                <EmptyState
                    kind="nothing-yet"
                    title={
                        theme === null
                            ? __('No theme is in use')
                            : __(':theme has no regions', {
                                  theme: theme.name,
                              })
                    }
                    description={
                        theme === null
                            ? undefined
                            : __(
                                  'There’s nothing to fill here while :theme is the active theme.',
                                  { theme: theme.name },
                              )
                    }
                />
            )}

            <Tabs defaultValue={chosen === null ? 'others' : 'active'}>
                <TabsList variant="underline">
                    <TabsTrigger value="active" count={count}>
                        {theme?.name ?? __('Active theme')}
                    </TabsTrigger>
                    <TabsTrigger
                        value="others"
                        data-test="others-tab"
                        count={others.length}
                    >
                        {__('Other themes')}
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
                                {__('From other themes')}{' '}
                                <span className="text-ink-2 tabular-nums">
                                    {others.length}
                                </span>
                            </h2>
                            <p className="text-ink-2 text-body">
                                {__(
                                    'Kept as they were from themes you aren’t using. Each shows again if you switch back to its theme.',
                                )}{' '}
                                <Link
                                    href={themesIndex().url}
                                    className="text-accent-ink underline"
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
