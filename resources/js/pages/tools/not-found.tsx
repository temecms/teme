import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import RedirectController from '@/actions/App/Http/Admin/Tools/RedirectController';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { Pager } from '@/components/list/pager';
import { RedirectForm } from '@/components/tools/redirect-form';
import type { RedirectEditing } from '@/components/tools/redirect-form';
import { RedirectsHead } from '@/components/tools/redirects-head';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

    const columns: Column<NotFoundRow>[] = [
        {
            id: 'address',
            owner: 'core',
            header: 'Address',
            cell: (row) => <span className="font-mono">{row.address}</span>,
        },
        {
            id: 'count',
            owner: 'core',
            header: 'Times',
            className: 'text-right',
            cell: (row) => row.count.toLocaleString(),
        },
        {
            id: 'first-seen',
            owner: 'core',
            header: 'First seen',
            className: 'whitespace-nowrap',
            cell: (row) => formatInZone(row.firstSeenAt, siteTimezone),
        },
        {
            id: 'last-seen',
            owner: 'core',
            header: 'Last seen',
            className: 'whitespace-nowrap',
            cell: (row) => formatInZone(row.lastSeenAt, siteTimezone),
        },
        {
            id: 'from',
            owner: 'core',
            header: 'Came from',
            cell: (row) =>
                row.referrerHost ?? (
                    <span className="text-muted-foreground">Not known</span>
                ),
        },
        {
            id: 'actions',
            owner: 'core',
            header: <span className="sr-only">Actions</span>,
            className: 'w-px text-right whitespace-nowrap',
            cell: (row) =>
                row.redirectId !== null ? (
                    <Badge variant="secondary" data-test="not-found-redirected">
                        Redirected
                    </Badge>
                ) : (
                    <div className="flex justify-end gap-1">
                        <Button
                            variant="ghost"
                            size="sm"
                            data-test="not-found-create"
                            aria-label={`Add a redirect from ${row.address}`}
                            onClick={() =>
                                setEditing({
                                    redirect: null,
                                    from: row.address,
                                })
                            }
                        >
                            Add a redirect
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
                                        aria-label={`Mark ${row.address} as gone`}
                                    >
                                        Mark as gone
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
            <Head title="Redirects" />

            <div className="space-y-4 p-6">
                <RedirectsHead
                    tab="not-found"
                    counts={counts}
                    actions={
                        settingsUrl !== null && (
                            <Button variant="outline" asChild>
                                <a href={settingsUrl}>Change log settings</a>
                            </Button>
                        )
                    }
                />

                <p className="text-muted-foreground text-[13px]">
                    One row per address. Only the site a reader came from is
                    kept: no IP address and no browser.
                </p>

                <p className="text-muted-foreground text-[13px]">
                    Keeps up to {keep.toLocaleString()} addresses. When it’s
                    full, the address seen least recently makes room. Bots
                    probing for WordPress and server files, such as
                    /wp-login.php, are ignored. Counts are written every minute,
                    so the newest take a moment to appear.
                </p>

                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.path}
                    caption="The 404 log"
                    empty={
                        <EmptyState
                            kind="nothing-yet"
                            title="Nothing logged"
                            description="Addresses readers ask for that don’t exist appear here."
                        />
                    }
                />

                <Pager
                    labels={{ newer: 'First page', older: 'Next page' }}
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
            </div>

            <RedirectForm editing={editing} onClose={() => setEditing(null)} />
        </>
    );
}

NotFound.layout = {
    breadcrumbs: [
        { title: 'Tools', href: toolsIndex() },
        { title: 'Redirects', href: index() },
    ],
};
