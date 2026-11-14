import { Form, Head, Link, router } from '@inertiajs/react';
import { Download, Info, Plus, Upload } from 'lucide-react';
import { useState } from 'react';
import RedirectController from '@/actions/App/Http/Admin/Tools/RedirectController';
import { ConfirmAction } from '@/components/confirm-action';
import { BulkBar } from '@/components/list/bulk-bar';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import InputError from '@/components/input-error';
import { Pager } from '@/components/list/pager';
import { RowLinks } from '@/components/list/row-links';
import {
    ImportRedirects,
    ImportReportDialog,
} from '@/components/tools/import-redirects';
import { RedirectForm } from '@/components/tools/redirect-form';
import type { RedirectEditing } from '@/components/tools/redirect-form';
import { RedirectsHead } from '@/components/tools/redirects-head';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { formatInZone } from '@/lib/zoned-time';
import { edit } from '@/routes/content';
import { show as addresses } from '@/routes/settings/addresses';
import { index as toolsIndex } from '@/routes/tools';
import { download, index } from '@/routes/tools/redirects';
import type { RedirectRow, RedirectsPage } from '@/types';
import { shownTitle } from '@/lib/titles';

/** The question before deleting: one redirect named by its address, or how many. */
function deleteTitle(rows: RedirectRow[]): string {
    return rows.length === 1
        ? `Delete the redirect from ${rows[0].from}?`
        : `Delete ${rows.length.toLocaleString()} redirects?`;
}

function deleteText(count: number): string {
    return count === 1
        ? 'Readers who follow an old link to it are no longer sent on, and the address appears in the 404 log if nothing else answers it. This can’t be undone.'
        : 'Readers who follow an old link to any of them are no longer sent on, and each address appears in the 404 log if nothing else answers it. This can’t be undone.';
}

function deleteLabel(count: number): string {
    return count === 1
        ? 'Delete redirect'
        : `Delete ${count.toLocaleString()} redirects`;
}

/** Where a redirect sends readers, in words (I-694). */
function RedirectTarget({ row }: { row: RedirectRow }) {
    const beneath = (address: string | null) =>
        address !== null && (
            <span className="text-ink-2 block font-mono text-[12px]">
                {address}
            </span>
        );

    switch (row.to.kind) {
        case null:
            return (
                <span className="text-ink-2">
                    Nowhere: it answers {row.status}
                </span>
            );
        case 'content':
            return row.to.title === null || row.to.contentId === null ? (
                <span className="text-ink-2">A deleted item</span>
            ) : (
                <>
                    <Link
                        href={edit(row.to.contentId)}
                        className="hover:underline"
                    >
                        {shownTitle(row.to.title)}
                    </Link>
                    {beneath(row.to.address)}
                </>
            );
        case 'media':
            return row.to.title === null ? (
                <span className="text-ink-2">A deleted file</span>
            ) : (
                <>
                    The file {row.to.title}
                    {beneath(row.to.address)}
                </>
            );
        case 'outside':
            return (
                <>
                    <span className="font-mono">{row.to.address}</span>
                    <span className="text-ink-2 block text-[12px]">
                        Another site
                    </span>
                </>
            );
        default:
            return <span className="font-mono">{row.to.address}</span>;
    }
}

/**
 * Tools › Redirects (I-871, I-872): every redirect, a page at a time, newest
 * first, found by address or note, with where it sends readers and what it
 * does now.
 */
export default function Redirects(page: RedirectsPage) {
    const {
        rows,
        newer,
        older,
        search,
        counts,
        siteTimezone,
        canManageAddresses,
        prefill,
        imported,
    } = page;
    const [importing, setImporting] = useState(false);
    const [report, setReport] = useState(imported);
    const [editing, setEditing] = useState<RedirectEditing | null>(
        prefill === null ? null : { redirect: null, from: prefill.from },
    );
    const adding = () => setEditing({ redirect: null, from: '' });
    const [selected, setSelected] = useState<string[]>([]);

    // A new search or page starts with nothing selected.
    const pageKey = [search, older ?? '', newer ?? ''].join('|');
    const [seenKey, setSeenKey] = useState(pageKey);

    if (seenKey !== pageKey) {
        setSeenKey(pageKey);
        setSelected([]);
    }

    const chosen = rows.filter((row) => selected.includes(row.id));
    const chosenIds = chosen.map((row) => row.id);
    const cleared = { preserveScroll: true, onSuccess: () => setSelected([]) };
    const hiddenIds = chosenIds.map((id) => (
        <input key={id} type="hidden" name="ids[]" value={id} />
    ));
    const keep = search ? { search } : {};

    const columns: Column<RedirectRow>[] = [
        {
            id: 'select',
            owner: 'core',
            className: 'w-10 pr-0',
            header: (
                <Checkbox
                    aria-label={
                        rows.length === 1
                            ? 'Select the 1 redirect shown'
                            : `Select all ${rows.length.toLocaleString()} redirects shown`
                    }
                    disabled={rows.length === 0}
                    checked={
                        rows.length > 0 &&
                        rows.every((row) => chosenIds.includes(row.id))
                    }
                    onCheckedChange={(checked) =>
                        setSelected(checked ? rows.map((row) => row.id) : [])
                    }
                />
            ),
            cell: (row) => (
                <Checkbox
                    aria-label={`Select the redirect from ${row.from}`}
                    checked={chosenIds.includes(row.id)}
                    onCheckedChange={(checked) =>
                        setSelected(
                            checked
                                ? [...chosenIds, row.id]
                                : chosenIds.filter((id) => id !== row.id),
                        )
                    }
                />
            ),
        },
        {
            id: 'from',
            owner: 'core',
            header: 'From',
            cell: (row) => (
                <>
                    <span className="font-mono">{row.from}</span>
                    {row.pattern && (
                        <Badge variant="secondary" className="ml-2">
                            Pattern
                        </Badge>
                    )}
                    {row.note !== '' && (
                        <span className="text-ink-2 block text-[12px]">
                            {row.note}
                        </span>
                    )}
                    <RowLinks
                        label={`Actions for the redirect from ${row.from}`}
                        more={`More actions for the redirect from ${row.from}`}
                        items={[
                            {
                                id: 'edit',
                                owner: 'core',
                                label: 'Edit',
                                onSelect: () =>
                                    setEditing({
                                        redirect: row,
                                        from: row.from,
                                    }),
                            },
                            {
                                id: 'switch',
                                owner: 'core',
                                label: row.on ? 'Switch off' : 'Switch on',
                                form: RedirectController.toggle.form(),
                                fields: {
                                    'ids[0]': row.id,
                                    on: row.on ? '0' : '1',
                                },
                            },
                            {
                                id: 'delete',
                                owner: 'core',
                                label: 'Delete',
                                destructive: true,
                                form: RedirectController.destroy.form(),
                                fields: { 'ids[0]': row.id },
                                wrap: (button) => (
                                    <ConfirmAction
                                        title={deleteTitle([row])}
                                        description={deleteText(1)}
                                        confirmLabel={deleteLabel(1)}
                                    >
                                        {button}
                                    </ConfirmAction>
                                ),
                            },
                        ]}
                    />
                </>
            ),
        },
        {
            id: 'to',
            owner: 'core',
            header: 'To',
            cell: (row) => <RedirectTarget row={row} />,
        },
        {
            id: 'status',
            owner: 'core',
            header: 'Status',
            cell: (row) => (
                <>
                    <span className="font-mono">{row.status}</span>
                    <span className="text-ink-2 block text-[12px]">
                        {row.statusLabel}
                    </span>
                </>
            ),
        },
        {
            id: 'origin',
            owner: 'core',
            header: 'Origin',
            cell: (row) => (row.origin === 'import' ? 'Imported' : 'Manual'),
        },
        {
            id: 'hits',
            owner: 'core',
            header: 'Hits',
            className: 'text-right',
            cell: (row) => row.hits.toLocaleString(),
        },
        {
            id: 'last-hit',
            owner: 'core',
            header: 'Last hit',
            className: 'whitespace-nowrap',
            cell: (row) =>
                row.lastHitAt === null ? (
                    <span className="text-ink-2">Never</span>
                ) : (
                    formatInZone(row.lastHitAt, siteTimezone)
                ),
        },
        {
            id: 'state',
            owner: 'core',
            header: 'State',
            cell: (row) => row.stateLabel,
        },
    ];

    return (
        <>
            <Head title="Redirects" />

            <div className="space-y-4 p-6">
                <RedirectsHead
                    tab="redirects"
                    counts={counts}
                    actions={
                        <>
                            <Button
                                variant="outline"
                                data-test="redirects-import"
                                onClick={() => setImporting(true)}
                            >
                                <Upload aria-hidden="true" />
                                Import from CSV
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="outline">
                                        <Download aria-hidden="true" />
                                        Export
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    aria-label="Export redirects"
                                >
                                    <DropdownMenuItem asChild>
                                        <a
                                            href={
                                                download({ format: 'csv' }).url
                                            }
                                            download
                                        >
                                            Export as CSV
                                        </a>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem asChild>
                                        <a
                                            href={
                                                download({ format: 'nginx' })
                                                    .url
                                            }
                                            download
                                        >
                                            Export for nginx
                                        </a>
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                            <Button data-test="redirect-add" onClick={adding}>
                                <Plus aria-hidden="true" />
                                Add a redirect
                            </Button>
                        </>
                    }
                />

                <div
                    role="note"
                    data-test="redirects-notice"
                    className="border-line flex gap-3 rounded-md border p-4 text-sm"
                >
                    <Info
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0"
                    />
                    <div className="space-y-2">
                        <p>
                            <strong className="font-medium">
                                Changed addresses need no redirect.
                            </strong>{' '}
                            When a post or page gets a new address, in the
                            editor or in{' '}
                            {canManageAddresses ? (
                                <Link href={addresses()} className="underline">
                                    Settings › Addresses
                                </Link>
                            ) : (
                                'Settings › Addresses'
                            )}
                            , its old address keeps sending readers to it by
                            itself. Add a redirect for an address Teme never
                            had, such as one from the old site.
                        </p>
                        <p>
                            A redirect answers only where no page does, so it
                            can never hide a live page. One that goes to a post
                            or page follows it when its address changes. Hits
                            are counted every minute, so the newest take a
                            moment to appear.
                        </p>
                    </div>
                </div>

                <form
                    role="search"
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const typed = new FormData(event.currentTarget).get(
                            'search',
                        );
                        const wanted =
                            typeof typed === 'string' ? typed.trim() : '';

                        router.get(
                            index({ query: wanted ? { search: wanted } : {} })
                                .url,
                        );
                    }}
                >
                    <Input
                        key={search}
                        name="search"
                        defaultValue={search}
                        placeholder="Address or note"
                        aria-label="Search redirects"
                        className="w-72"
                    />
                    <Button variant="outline" type="submit">
                        Search
                    </Button>
                </form>

                {chosen.length > 0 && (
                    <BulkBar
                        count={chosen.length}
                        onClear={() => setSelected([])}
                    >
                        <Form
                            {...RedirectController.toggle.form()}
                            options={cleared}
                        >
                            {({ processing }) => (
                                <>
                                    {hiddenIds}
                                    <input type="hidden" name="on" value="1" />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Switch on
                                    </Button>
                                </>
                            )}
                        </Form>
                        <Form
                            {...RedirectController.toggle.form()}
                            options={cleared}
                        >
                            {({ processing }) => (
                                <>
                                    {hiddenIds}
                                    <input type="hidden" name="on" value="0" />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Switch off
                                    </Button>
                                </>
                            )}
                        </Form>
                        <Form
                            {...RedirectController.destroy.form()}
                            options={cleared}
                        >
                            {({ processing, errors }) => (
                                <>
                                    {hiddenIds}
                                    <ConfirmAction
                                        title={deleteTitle(chosen)}
                                        description={deleteText(chosen.length)}
                                        confirmLabel={deleteLabel(
                                            chosen.length,
                                        )}
                                    >
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="destructive"
                                            disabled={processing}
                                        >
                                            Delete
                                        </Button>
                                    </ConfirmAction>
                                    <InputError message={errors.redirects} />
                                </>
                            )}
                        </Form>
                    </BulkBar>
                )}

                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    caption="Redirects"
                    empty={
                        search ? (
                            <EmptyState
                                kind="nothing-matches"
                                title={`No redirects match “${search}”`}
                                clearHref={index().url}
                            />
                        ) : (
                            <EmptyState
                                kind="nothing-yet"
                                title="No redirects yet"
                                description="A redirect sends readers on from an address this site no longer has."
                                action={
                                    <Button onClick={adding}>
                                        <Plus aria-hidden="true" />
                                        Add a redirect
                                    </Button>
                                }
                            />
                        )
                    }
                />

                <Pager
                    newer={
                        newer
                            ? index({ query: { ...keep, before: newer } }).url
                            : null
                    }
                    older={
                        older
                            ? index({ query: { ...keep, after: older } }).url
                            : null
                    }
                />
            </div>

            <RedirectForm editing={editing} onClose={() => setEditing(null)} />
            <ImportRedirects open={importing} onOpenChange={setImporting} />
            <ImportReportDialog
                report={report}
                onClose={() => setReport(null)}
            />
        </>
    );
}

Redirects.layout = {
    breadcrumbs: [
        { title: 'Tools', href: toolsIndex() },
        { title: 'Redirects', href: index() },
    ],
};
