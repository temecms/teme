import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { Download, Plus, Upload } from 'lucide-react';
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
import { ListPage } from '@/components/patterns/list-page';
import {
    ImportRedirects,
    ImportReportDialog,
} from '@/components/tools/import-redirects';
import { RedirectForm } from '@/components/tools/redirect-form';
import type { RedirectEditing } from '@/components/tools/redirect-form';
import {
    RedirectsHead,
    redirectsDescription,
} from '@/components/tools/redirects-head';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import { shownTitle } from '@/lib/titles';
import { formatInZone } from '@/lib/zoned-time';
import { edit } from '@/routes/content';
import { show as addresses } from '@/routes/settings/addresses';
import { index as toolsIndex } from '@/routes/tools';
import { download, index } from '@/routes/tools/redirects';
import type { RedirectRow, RedirectsPage } from '@/types';

/** The question before deleting: one redirect named by its address, or how many. */
function deleteTitle(rows: RedirectRow[]): string {
    return rows.length === 1
        ? __('Delete the redirect from :from?', { from: rows[0].from })
        : __('Delete :count redirects?', {
              count: rows.length.toLocaleString(),
          });
}

function deleteText(count: number): string {
    return count === 1
        ? __(
              'Readers who follow an old link to it are no longer sent on, and the address appears in the 404 log if nothing else answers it. This can’t be undone.',
          )
        : __(
              'Readers who follow an old link to any of them are no longer sent on, and each address appears in the 404 log if nothing else answers it. This can’t be undone.',
          );
}

function deleteLabel(count: number): string {
    return count === 1
        ? __('Delete redirect')
        : __('Delete :count redirects', { count: count.toLocaleString() });
}

/** Where a redirect sends readers, in words (I-694). */
function RedirectTarget({ row }: { row: RedirectRow }) {
    const beneath = (address: string | null) =>
        address !== null && (
            <span className="text-ink-2 text-caption block font-mono">
                {address}
            </span>
        );

    switch (row.to.kind) {
        case null:
            return (
                <span className="text-ink-2">
                    {__('Nowhere: it answers :status', { status: row.status })}
                </span>
            );
        case 'content':
            return row.to.title === null || row.to.contentId === null ? (
                <span className="text-ink-2">{__('A deleted item')}</span>
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
                <span className="text-ink-2">{__('A deleted file')}</span>
            ) : (
                <>
                    {__('The file :name', { name: row.to.title })}
                    {beneath(row.to.address)}
                </>
            );
        case 'outside':
            return (
                <>
                    <span className="font-mono">{row.to.address}</span>
                    <span className="text-ink-2 text-caption block">
                        {__('Another site')}
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
    setLayoutProps({
        breadcrumbs: [
            { title: __('Tools'), href: toolsIndex() },
            { title: __('Redirects'), href: index() },
        ],
    });

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
                            ? __('Select the 1 redirect shown')
                            : __('Select all :count redirects shown', {
                                  count: rows.length.toLocaleString(),
                              })
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
                    aria-label={__('Select the redirect from :from', {
                        from: row.from,
                    })}
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
            header: __('From'),
            cell: (row) => (
                <>
                    <span className="font-mono">{row.from}</span>
                    {row.pattern && (
                        <StatusPill tone="neutral" className="ms-2">
                            {__('Pattern')}
                        </StatusPill>
                    )}
                    {row.note !== '' && (
                        <span className="text-ink-2 text-caption block">
                            {row.note}
                        </span>
                    )}
                    <RowLinks
                        label={__('Actions for the redirect from :from', {
                            from: row.from,
                        })}
                        more={__('More actions for the redirect from :from', {
                            from: row.from,
                        })}
                        items={[
                            {
                                id: 'edit',
                                owner: 'core',
                                label: __('Edit'),
                                onSelect: () =>
                                    setEditing({
                                        redirect: row,
                                        from: row.from,
                                    }),
                            },
                            {
                                id: 'switch',
                                owner: 'core',
                                label: row.on
                                    ? __('Switch off')
                                    : __('Switch on'),
                                form: RedirectController.toggle.form(),
                                fields: {
                                    'ids[0]': row.id,
                                    on: row.on ? '0' : '1',
                                },
                            },
                            {
                                id: 'delete',
                                owner: 'core',
                                label: __('Delete'),
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
            header: __('To'),
            cell: (row) => <RedirectTarget row={row} />,
        },
        {
            id: 'status',
            owner: 'core',
            header: __('Status'),
            cell: (row) => (
                <>
                    <span className="font-mono">{row.status}</span>
                    <span className="text-ink-2 text-caption block">
                        {row.statusLabel}
                    </span>
                </>
            ),
        },
        {
            id: 'origin',
            owner: 'core',
            header: __('Origin'),
            cell: (row) =>
                row.origin === 'import' ? __('Imported') : __('Manual'),
        },
        {
            id: 'hits',
            owner: 'core',
            header: __('Hits'),
            className: 'text-right',
            cell: (row) => row.hits.toLocaleString(),
        },
        {
            id: 'last-hit',
            owner: 'core',
            header: __('Last hit'),
            className: 'whitespace-nowrap',
            cell: (row) =>
                row.lastHitAt === null ? (
                    <span className="text-ink-2">{__('Never')}</span>
                ) : (
                    formatInZone(row.lastHitAt, siteTimezone)
                ),
        },
        {
            id: 'state',
            owner: 'core',
            header: __('State'),
            cell: (row) => row.stateLabel,
        },
    ];

    return (
        <>
            <Head title={__('Redirects')} />

            <ListPage
                title={__('Redirects')}
                description={redirectsDescription('redirects')}
                actions={
                    <>
                        <Button
                            variant="outline"
                            data-test="redirects-import"
                            onClick={() => setImporting(true)}
                        >
                            <Upload aria-hidden="true" />
                            {__('Import from CSV')}
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline">
                                    <Download aria-hidden="true" />
                                    {__('Export')}
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                align="end"
                                aria-label={__('Export redirects')}
                            >
                                <DropdownMenuItem asChild>
                                    <a
                                        href={download({ format: 'csv' }).url}
                                        download
                                    >
                                        {__('Export as CSV')}
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <a
                                        href={download({ format: 'nginx' }).url}
                                        download
                                    >
                                        {__('Export for nginx')}
                                    </a>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <Button data-test="redirect-add" onClick={adding}>
                            <Plus aria-hidden="true" />
                            {__('Add a redirect')}
                        </Button>
                    </>
                }
            >
                <RedirectsHead tab="redirects" counts={counts} />

                <Notice
                    tone="info"
                    data-test="redirects-notice"
                    title={__('Changed addresses need no redirect.')}
                >
                    <div className="grid gap-2">
                        <p>
                            {__(
                                'When a post or page gets a new address, in the editor or in',
                            )}{' '}
                            {canManageAddresses ? (
                                <Link href={addresses()} className="underline">
                                    {__('Settings › Addresses')}
                                </Link>
                            ) : (
                                __('Settings › Addresses')
                            )}
                            {__(
                                ', its old address keeps sending readers to it by itself. Add a redirect for an address Teme never had, such as one from the old site.',
                            )}
                        </p>
                        <p>
                            {__(
                                'A redirect answers only where no page does, so it can never hide a live page. One that goes to a post or page follows it when its address changes. Hits are counted every minute, so the newest take a moment to appear.',
                            )}
                        </p>
                    </div>
                </Notice>

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
                        placeholder={__('Address or note')}
                        aria-label={__('Search redirects')}
                        className="w-72"
                    />
                    <Button variant="outline" type="submit">
                        {__('Search')}
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
                                        {__('Switch on')}
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
                                        {__('Switch off')}
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
                                            {__('Delete')}
                                        </Button>
                                    </ConfirmAction>
                                    <InputError
                                        id="redirects-bulk-error"
                                        message={errors.redirects}
                                    />
                                </>
                            )}
                        </Form>
                    </BulkBar>
                )}

                <DataTable
                    columns={columns}
                    rows={rows}
                    rowKey={(row) => row.id}
                    caption={__('Redirects')}
                    empty={
                        search ? (
                            <EmptyState
                                kind="nothing-matches"
                                title={__('No redirects match “:search”', {
                                    search,
                                })}
                                clearHref={index().url}
                            />
                        ) : (
                            <EmptyState
                                kind="nothing-yet"
                                title={__('No redirects yet')}
                                description={__(
                                    'A redirect sends readers on from an address this site no longer has.',
                                )}
                                action={
                                    <Button onClick={adding}>
                                        <Plus aria-hidden="true" />
                                        {__('Add a redirect')}
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

                <RedirectForm
                    editing={editing}
                    onClose={() => setEditing(null)}
                />
                <ImportRedirects open={importing} onOpenChange={setImporting} />
                <ImportReportDialog
                    report={report}
                    onClose={() => setReport(null)}
                />
            </ListPage>
        </>
    );
}
