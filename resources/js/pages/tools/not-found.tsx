import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import RedirectController from '@/actions/App/Http/Admin/Tools/RedirectController';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { Pager } from '@/components/list/pager';
import { ListPage } from '@/components/patterns/list-page';
import { RedirectForm } from '@/components/tools/redirect-form';
import type { RedirectEditing } from '@/components/tools/redirect-form';
import {
    RedirectsHead,
    redirectsDescription,
} from '@/components/tools/redirects-head';
import { Button } from '@/components/ui/button';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import { formatInZone } from '@/lib/zoned-time';
import { index as toolsIndex } from '@/routes/tools';
import { index, notFound } from '@/routes/tools/redirects';
import type { NotFoundPage, NotFoundRow } from '@/types';

/**
 * The 404 log (I-298, I-871): the addresses readers asked for that don't
 * exist, the most asked for first, beside Tools › Redirects.
 */
export default function NotFound(page: NotFoundPage) {
    const { rows, next, paged, keep, counts, siteTimezone, settingsUrl } = page;
    const [editing, setEditing] = useState<RedirectEditing | null>(null);

    setLayoutProps({
        breadcrumbs: [
            { title: __('Tools'), href: toolsIndex() },
            { title: __('Redirects'), href: index() },
        ],
    });

    const columns: Column<NotFoundRow>[] = [
        {
            id: 'address',
            owner: 'core',
            header: __('Address'),
            cell: (row) => <span className="font-mono">{row.address}</span>,
        },
        {
            id: 'count',
            owner: 'core',
            header: __('Times'),
            className: 'text-right',
            cell: (row) => row.count.toLocaleString(),
        },
        {
            id: 'first-seen',
            owner: 'core',
            header: __('First seen'),
            className: 'whitespace-nowrap',
            cell: (row) => formatInZone(row.firstSeenAt, siteTimezone),
        },
        {
            id: 'last-seen',
            owner: 'core',
            header: __('Last seen'),
            className: 'whitespace-nowrap',
            cell: (row) => formatInZone(row.lastSeenAt, siteTimezone),
        },
        {
            id: 'from',
            owner: 'core',
            header: __('Came from'),
            cell: (row) =>
                row.referrerHost ?? (
                    <span className="text-ink-2">{__('Not known')}</span>
                ),
        },
        {
            id: 'actions',
            owner: 'core',
            header: <span className="sr-only">{__('Actions')}</span>,
            className: 'w-px text-right whitespace-nowrap',
            cell: (row) =>
                row.redirectId !== null ? (
                    <StatusPill tone="ok" data-test="not-found-redirected">
                        {__('Redirected')}
                    </StatusPill>
                ) : (
                    <div className="flex justify-end gap-1">
                        <Button
                            variant="ghost"
                            size="sm"
                            data-test="not-found-create"
                            aria-label={__('Add a redirect from :address', {
                                address: row.address,
                            })}
                            onClick={() =>
                                setEditing({
                                    redirect: null,
                                    from: row.address,
                                })
                            }
                        >
                            {__('Add a redirect')}
                        </Button>
                        <Form
                            {...RedirectController.gone.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="from"
                                        value={row.address}
                                    />
                                    <Button
                                        type="submit"
                                        variant="ghost"
                                        size="sm"
                                        disabled={processing}
                                        data-test="not-found-gone"
                                        aria-label={__(
                                            'Mark :address as gone',
                                            {
                                                address: row.address,
                                            },
                                        )}
                                    >
                                        {__('Mark as gone')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                ),
        },
    ];

    return (
        <>
            <Head title={__('Redirects')} />

            <ListPage
                title={__('Redirects')}
                description={redirectsDescription('not-found')}
                actions={
                    settingsUrl !== null && (
                        <Button variant="outline" asChild>
                            <a href={settingsUrl}>
                                {__('Change log settings')}
                            </a>
                        </Button>
                    )
                }
            >
                <RedirectsHead tab="not-found" counts={counts} />

                <p className="text-ink-2 text-small">
                    {__(
                        'One row per address. Only the site a reader came from is kept: no IP address and no browser.',
                    )}
                </p>

                <p className="text-ink-2 text-small">
                    {__(
                        'Keeps up to :keep addresses. When it’s full, the address seen least recently makes room. Bots probing for WordPress and server files, such as /wp-login.php, are ignored. Counts are written every minute, so the newest take a moment to appear.',
                        { keep: keep.toLocaleString() },
                    )}
                </p>

                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.path}
                    caption={__('The 404 log')}
                    empty={
                        <EmptyState
                            kind="nothing-yet"
                            title={__('Nothing logged')}
                            description={__(
                                'Addresses readers ask for that don’t exist appear here.',
                            )}
                        />
                    }
                />

                <Pager
                    labels={{
                        newer: __('First page'),
                        older: __('Next page'),
                    }}
                    newer={paged ? notFound().url : null}
                    older={
                        next
                            ? notFound({
                                  query: {
                                      count: String(next.count),
                                      path: next.path,
                                  },
                              }).url
                            : null
                    }
                />

                <RedirectForm
                    editing={editing}
                    onClose={() => setEditing(null)}
                />
            </ListPage>
        </>
    );
}
