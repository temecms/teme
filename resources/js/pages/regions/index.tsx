import { Form, Head, Link } from '@inertiajs/react';
import RegionsController from '@/actions/App/Http/Admin/RegionsController';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { ListPage } from '@/components/patterns/list-page';
import { Button } from '@/components/ui/button';
import { TermChips } from '@/components/ui/term-chips';
import { __ } from '@/lib/i18n';
import { edit as editContent } from '@/routes/content';
import type {
    OtherRegionRow,
    RegionRow,
    RegionState,
    RegionsIndexPage,
} from '@/types';

const STATES: Record<RegionState, string> = {
    empty: 'Empty',
    draft: 'Draft',
    published: 'Published',
    changed: 'Published, with unpublished changes',
};

function edited(at: string | null): string {
    return at === null
        ? '—'
        : new Date(at).toLocaleString([], {
              dateStyle: 'medium',
              timeStyle: 'short',
          });
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
    const columns: Column<RegionRow>[] = [
        {
            id: 'label',
            owner: 'core',
            header: 'Region',
            cell: (row) => <span className="font-semibold">{row.label}</span>,
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
            header: 'State',
            cell: (row) => (
                <span
                    className={row.state === 'empty' ? 'text-ink-2' : undefined}
                >
                    {STATES[row.state]}
                </span>
            ),
        },
        {
            id: 'edited',
            owner: 'core',
            header: 'Last edited',
            cell: (row) => (
                <span className="text-ink-2">{edited(row.editedAt)}</span>
            ),
        },
        {
            id: 'actions',
            owner: 'core',
            header: <span className="sr-only">Actions</span>,
            className: 'w-px text-right',
            cell: (row) =>
                row.contentId !== null ? (
                    <Button variant="neutral" size="sm" asChild>
                        <Link href={editContent(row.contentId).url}>
                            Edit
                            <span className="sr-only"> {row.label}</span>
                        </Link>
                    </Button>
                ) : (
                    theme !== null && (
                        <Form {...RegionsController.store.form()}>
                            {({ processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="theme"
                                        value={theme.code}
                                    />
                                    <input
                                        type="hidden"
                                        name="region"
                                        value={row.key}
                                    />
                                    <Button
                                        variant="neutral"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        Create
                                        <span className="sr-only">
                                            {' '}
                                            {row.label}
                                        </span>
                                    </Button>
                                </>
                            )}
                        </Form>
                    )
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
            cell: (row) =>
                row.themeName ?? (
                    <span className="text-ink-2">
                        Theme no longer installed
                    </span>
                ),
        },
        {
            id: 'state',
            owner: 'core',
            header: 'State',
            cell: (row) => STATES[row.state],
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
            title="Regions"
            description={
                theme === null
                    ? 'Your own content in the spots the active theme sets aside, such as a box after each article.'
                    : `Your own content in the spots the active theme, ${theme.name}, sets aside, such as a box after each article. Regions come from whichever theme is active: switch themes and you fill that theme’s regions instead. Each region takes only the cards its theme allows.`
            }
        >
            <Head title="Regions" />

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
