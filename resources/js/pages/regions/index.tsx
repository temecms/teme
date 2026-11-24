import { Head, router, setLayoutProps } from '@inertiajs/react';
import RegionsController from '@/actions/App/Http/Admin/RegionsController';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import type { RowAction } from '@/components/list/row-actions';
import { RowLine } from '@/components/list/row-line';
import { ListPage } from '@/components/patterns/list-page';
import { StatusPill } from '@/components/ui/status-pill';
import { TermChips } from '@/components/ui/term-chips';
import { useSectionCrumb } from '@/lib/crumbs';
import { __, __n } from '@/lib/i18n';
import { edit as editContent } from '@/routes/content';
import { index as regionsIndex } from '@/routes/regions';
import type {
    OtherRegionRow,
    RegionRow,
    RegionState,
    RegionsIndexPage,
} from '@/types';
import { formatDateTime } from '@/lib/dates';

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
 * Content › Regions (I-395): the site's own content the theme places, such
 * as a box after each article, each held to the cards and the number of
 * blocks the theme allows. Regions kept from themes not in use are listed
 * openly as Unplaced, read-only, never folded away.
 */
export default function RegionsIndex({
    theme,
    regions,
    others,
}: RegionsIndexPage) {
    const title = __('Regions');
    const contentCrumb = useSectionCrumb('content');

    setLayoutProps({
        breadcrumbs: [contentCrumb, { title, href: regionsIndex() }],
    });

    /** Under the name: Edit a region that has content, else Create it. */
    const lineActions = (row: RegionRow): RowAction[] => {
        if (row.contentId !== null) {
            return [
                {
                    id: 'edit',
                    owner: 'core',
                    label: __('Edit'),
                    href: editContent(row.contentId).url,
                },
            ];
        }

        if (theme === null) {
            return [];
        }

        return [
            {
                id: 'create',
                owner: 'core',
                label: __('Create'),
                onSelect: () =>
                    router.post(RegionsController.store.url(), {
                        theme: theme.code,
                        region: row.key,
                    }),
            },
        ];
    };

    const columns: Column<RegionRow>[] = [
        {
            id: 'label',
            owner: 'core',
            header: __('Region'),
            cell: (row) => (
                <div className="grid min-w-0 gap-0.5">
                    <span className="text-ink font-semibold">{row.label}</span>
                    <RowLine title={row.label} items={lineActions(row)} />
                </div>
            ),
        },
        {
            id: 'cards',
            owner: 'core',
            header: 'Cards it takes',
            cell: (row) => (
                <div className="grid gap-1">
                    {row.cardLabels.length === 0 ? (
                        <span className="text-ink-2">Text only</span>
                    ) : (
                        <div data-test="region-cards" className="min-w-0">
                            <TermChips
                                terms={row.cardLabels.map((label) => ({
                                    id: label,
                                    name: label,
                                }))}
                                noun={{ one: __('card'), many: __('cards') }}
                                emptyLabel={__('No cards')}
                            />
                        </div>
                    )}
                    <span className="text-ink-2 text-small">{row.limit}</span>
                </div>
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

            <div className="grid gap-6">
                <DataTable
                    columns={columns}
                    rows={regions}
                    rowKey={(row) => row.key}
                    caption={
                        theme === null ? 'Regions' : `Regions in ${theme.name}`
                    }
                    empty={
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
                    }
                />

                {others.length > 0 && (
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
                                }
                            </p>
                        </div>
                        <DataTable
                            columns={otherColumns}
                            rows={others}
                            rowKey={(row) => row.slug}
                            caption="Regions kept from themes not in use"
                        />
                    </section>
                )}
            </div>
        </ListPage>
    );
}
