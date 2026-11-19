import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { ChevronDown, Download, Ellipsis, Plus, Upload } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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
import { SelectField } from '@/components/ui/select-field';
import { StatusPill } from '@/components/ui/status-pill';
import { QuietNote } from '@/components/tools/quiet-note';
import { __, __n } from '@/lib/i18n';
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

/** A redirect's state as a pill: on, off, or waiting, with what it waits for under it. */
function RedirectState({ row }: { row: RedirectRow }) {
    if (row.state === 'on') {
        return <StatusPill tone="ok">{__('On')}</StatusPill>;
    }

    if (row.state === 'off') {
        return <StatusPill tone="neutral">{__('Off')}</StatusPill>;
    }

    const waiting = row.state.startsWith('waiting');
    const sentence = row.stateLabel.replace(/^[^:]*:\s*/, '');

    return (
        <div className="grid justify-items-start gap-1">
            <StatusPill tone={waiting ? 'info' : 'warn'}>
                {waiting ? __('Waiting') : row.stateLabel.split(':')[0]}
            </StatusPill>
            {waiting && sentence !== '' && (
                <span className="text-ink-2 text-caption">
                    {sentence.charAt(0).toUpperCase() + sentence.slice(1)}
                </span>
            )}
        </div>
    );
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
        filters,
        statuses,
        shown,
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
    const pageKey = [
        search,
        filters.on ?? '',
        filters.status ?? '',
        filters.origin ?? '',
        older ?? '',
        newer ?? '',
    ].join('|');
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
    const filtersOn =
        filters.on !== null ||
        filters.status !== null ||
        filters.origin !== null;
    const address = (next: {
        search?: string;
        state?: string | null;
        status?: string | null;
        origin?: string | null;
        after?: string;
        before?: string;
    }) => {
        const query: Record<string, string> = {};
        const wanted = {
            search: next.search ?? search,
            state: next.state === undefined ? filters.on : next.state,
            status:
                next.status === undefined
                    ? filters.status === null
                        ? null
                        : String(filters.status)
                    : next.status,
            origin: next.origin === undefined ? filters.origin : next.origin,
        };

        for (const [key, value] of Object.entries(wanted)) {
            if (value) {
                query[key] = value;
            }
        }

        if (next.after) {
            query.after = next.after;
        }

        if (next.before) {
            query.before = next.before;
        }

        return index({ query }).url;
    };

    // The search runs as it is typed, a moment after the last key.
    const [typed, setTyped] = useState(search);
    const typing = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => setTyped(search), [search]);

    const searchFor = (value: string) => {
        setTyped(value);

        if (typing.current !== null) {
            clearTimeout(typing.current);
        }

        typing.current = setTimeout(() => {
            router.get(
                address({ search: value.trim() }),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
    };

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
                    <span className="font-mono font-semibold">{row.from}</span>
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
                row.origin === 'import' ? __('Imported') : __('Added here'),
        },
        {
            id: 'hits',
            owner: 'core',
            header: __('Hits'),
            className: 'whitespace-nowrap',
            cell: (row) => (
                <>
                    <span className="tabular-nums">
                        {row.hits.toLocaleString()}
                    </span>
                    <span className="text-ink-2 text-caption block">
                        {row.lastHitAt === null
                            ? __('Never')
                            : formatInZone(row.lastHitAt, siteTimezone)}
                    </span>
                </>
            ),
        },
        {
            id: 'state',
            owner: 'core',
            header: __('State'),
            cell: (row) => <RedirectState row={row} />,
        },
        {
            id: 'more',
            owner: 'core',
            header: <span className="sr-only">{__('Actions')}</span>,
            className: 'w-px text-right',
            cell: (row) => (
                <>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                aria-label={__(
                                    'More actions for the redirect from :from',
                                    { from: row.from },
                                )}
                                data-test="redirect-more"
                            >
                                <Ellipsis aria-hidden="true" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                onSelect={() =>
                                    setEditing({
                                        redirect: row,
                                        from: row.from,
                                    })
                                }
                            >
                                {__('Edit')}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={() =>
                                    document
                                        .getElementById(
                                            `redirect-switch-${row.id}`,
                                        )
                                        ?.click()
                                }
                            >
                                {row.on ? __('Switch off') : __('Switch on')}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() =>
                                    document
                                        .getElementById(
                                            `redirect-delete-${row.id}`,
                                        )
                                        ?.click()
                                }
                            >
                                {__('Delete')}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Form
                        {...RedirectController.toggle.form()}
                        options={{ preserveScroll: true }}
                        hidden
                    >
                        <input type="hidden" name="ids[0]" value={row.id} />
                        <input
                            type="hidden"
                            name="on"
                            value={row.on ? '0' : '1'}
                        />
                        <Button
                            type="submit"
                            id={`redirect-switch-${row.id}`}
                            tabIndex={-1}
                            hidden
                        />
                    </Form>
                    <Form
                        {...RedirectController.destroy.form()}
                        options={{ preserveScroll: true }}
                        hidden
                    >
                        <input type="hidden" name="ids[0]" value={row.id} />
                        <ConfirmAction
                            title={deleteTitle([row])}
                            description={deleteText(1)}
                            confirmLabel={deleteLabel(1)}
                        >
                            <Button
                                type="submit"
                                id={`redirect-delete-${row.id}`}
                                tabIndex={-1}
                                hidden
                            />
                        </ConfirmAction>
                    </Form>
                </>
            ),
        },
    ];

    return (
        <>
            <Head title={__('Redirects')} />

            <ListPage
                title={__('Redirects')}
                description={redirectsDescription()}
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
                                    <ChevronDown aria-hidden="true" />
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

                <QuietNote testId="redirects-notice">
                    <p>
                        <strong className="text-ink font-semibold">
                            {__('Changed addresses need no redirect.')}
                        </strong>{' '}
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
                        )}{' '}
                        {__(
                            'A redirect answers only where no page does, so it can never hide a live page.',
                        )}
                    </p>
                </QuietNote>

                <div
                    data-test="list-filters"
                    className="flex flex-wrap items-center gap-2"
                >
                    <Input
                        type="search"
                        name="search"
                        value={typed}
                        onChange={(event) => searchFor(event.target.value)}
                        placeholder={__('Address or note')}
                        aria-label={__('Search redirects')}
                        className="w-72"
                    />
                    <SelectField
                        aria-label={__('State')}
                        emptyLabel={__('All states')}
                        className="w-40"
                        value={filters.on ?? ''}
                        options={[
                            { value: 'on', label: __('On') },
                            { value: 'off', label: __('Off') },
                        ]}
                        onValueChange={(next) =>
                            router.visit(address({ state: next || null }), {
                                preserveScroll: true,
                            })
                        }
                    />
                    <SelectField
                        aria-label={__('Status')}
                        emptyLabel={__('Any status')}
                        className="w-56"
                        value={
                            filters.status === null
                                ? ''
                                : String(filters.status)
                        }
                        options={statuses.map((status) => ({
                            value: String(status.value),
                            label: `${status.value} ${status.label}`,
                        }))}
                        onValueChange={(next) =>
                            router.visit(address({ status: next || null }), {
                                preserveScroll: true,
                            })
                        }
                    />
                    <SelectField
                        aria-label={__('Origin')}
                        emptyLabel={__('Any origin')}
                        className="w-44"
                        value={filters.origin ?? ''}
                        options={[
                            { value: 'manual', label: __('Added here') },
                            { value: 'import', label: __('Imported') },
                        ]}
                        onValueChange={(next) =>
                            router.visit(address({ origin: next || null }), {
                                preserveScroll: true,
                            })
                        }
                    />
                    {filtersOn && (
                        <Button variant="ghost" asChild>
                            <Link
                                href={address({
                                    state: null,
                                    status: null,
                                    origin: null,
                                })}
                            >
                                {__('Clear filters')}
                            </Link>
                        </Button>
                    )}
                </div>

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
                        search || filtersOn ? (
                            <EmptyState
                                kind="nothing-matches"
                                title={
                                    search
                                        ? __('No redirects match “:search”', {
                                              search,
                                          })
                                        : __('No redirects match')
                                }
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

                {rows.length > 0 && (
                    <div className="grid gap-3">
                        <p className="text-small text-ink-3">
                            {__n(
                                ':count redirect, newest first. Hits are counted every minute, so the newest take a moment to appear.|:count redirects, newest first. Hits are counted every minute, so the newest take a moment to appear.',
                                shown,
                                { count: shown.toLocaleString() },
                            )}
                        </p>
                        {(newer !== null || older !== null) && (
                            <Pager
                                newer={
                                    newer ? address({ before: newer }) : null
                                }
                                older={older ? address({ after: older }) : null}
                            />
                        )}
                    </div>
                )}

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
