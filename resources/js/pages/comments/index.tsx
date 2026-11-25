import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import { CommentDetailsDrawer } from '@/components/comments/comment-details';
import { CommentStatusPill } from '@/components/comments/comment-status-pill';
import { Commenter } from '@/components/comments/commenter';
import { DeleteComments } from '@/components/comments/delete-comments';
import { EmptyComments } from '@/components/comments/empty-comments';
import { InlineReply } from '@/components/comments/inline-reply';
import { QuickEdit } from '@/components/comments/quick-edit';
import type { QuickEditTarget } from '@/components/comments/quick-edit';
import {
    takesReplyAndQuickEdit,
    useCommentKeys,
} from '@/components/comments/use-comment-keys';
import { BulkBar } from '@/components/list/bulk-bar';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { FilterRow } from '@/components/list/filter-row';
import { NumberedPager } from '@/components/list/numbered-pager';
import type { RowAction } from '@/components/list/row-actions';
import { RowLine } from '@/components/list/row-line';
import { RowMenu } from '@/components/list/row-menu';
import { SearchField } from '@/components/list/search-field';
import { InPageNav } from '@/components/patterns/in-page-nav';
import { ListPage } from '@/components/patterns/list-page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Kbd } from '@/components/ui/kbd';
import { StatusPill } from '@/components/ui/status-pill';
import { TextButton } from '@/components/ui/text-button';
import { useSectionCrumb } from '@/lib/crumbs';
import { formatDate, formatTime } from '@/lib/dates';
import { __, __n } from '@/lib/i18n';
import { shownTitle } from '@/lib/titles';
import { index, moderate } from '@/routes/comments';
import { edit } from '@/routes/content';
import type { CommentRow, CommentsIndexPage, CommentView } from '@/types';

/** One move: the status it sends, or `delete` for the confirmation. */
type Move = { label: string; to: string; destructive?: boolean };

/**
 * The Comments screen (docs/proposals/content-lists.md): views with counts,
 * a search, the list narrowed to an item, a page of comments to moderate one
 * at a time or in bulk, with Undo, and a drawer for one comment's details;
 * Quick edit, the inline reply, Empty spam and Empty trash, and the keys.
 * A row's line holds its state action, Reply, Quick edit and Details; its
 * menu holds the rest.
 */
export default function CommentsIndex(page: CommentsIndexPage) {
    const {
        view,
        views,
        rows,
        paging,
        filters,
        item,
        own,
        seesAddresses,
        siteTimezone,
        details,
        openedAt,
        emptying,
        trashDays,
    } = page;
    const { preferences } = usePage().props;
    const perPage = preferences['lists.per_page'];
    const [selected, setSelected] = useState<string[]>([]);
    const [deleting, setDeleting] = useState<string[] | null>(null);
    const [replying, setReplying] = useState<string | null>(null);
    const [editing, setEditing] = useState<QuickEditTarget | null>(null);
    const [emptyingOpen, setEmptyingOpen] = useState(false);

    const approve: Move = { label: __('Approve'), to: 'approved' };
    const unapprove: Move = { label: __('Unapprove'), to: 'pending' };
    const spam: Move = { label: __('Mark as spam'), to: 'spam' };
    const notSpam: Move = { label: __('Mark as not spam'), to: 'restore' };
    const restore: Move = { label: __('Restore'), to: 'restore' };
    const trash: Move = {
        label: __('Move to trash'),
        to: 'trash',
        destructive: true,
    };
    const remove: Move = {
        label: __('Delete permanently'),
        to: 'delete',
        destructive: true,
    };

    // The bulk bar's moves by view: the first three are buttons, the rest
    // and Delete permanently are under More.
    const bulkMoves: Record<CommentView, Move[]> = {
        all: [approve, unapprove, spam, trash],
        pending: [approve, spam, trash],
        approved: [unapprove, spam, trash],
        spam: [notSpam, remove],
        trash: [restore, remove],
    };

    // Only what the page holds stays selected, and a new view, item, search
    // or page starts with nothing selected.
    const pageKey = [
        view,
        item?.id ?? '',
        filters.search ?? '',
        paging.number ?? '',
        paging.older ?? '',
        paging.newer ?? '',
    ].join('|');
    const [seenKey, setSeenKey] = useState(pageKey);

    if (seenKey !== pageKey) {
        setSeenKey(pageKey);
        setSelected([]);
    }

    const chosen = selected.filter((id) => rows.some((row) => row.id === id));

    const keep = {
        ...(filters.search ? { search: filters.search } : {}),
        ...(filters.item ? { item: filters.item } : {}),
    };
    const visit = (query: Record<string, string>) =>
        router.get(index({ query }).url);
    const contentCrumb = useSectionCrumb('content');

    setLayoutProps({
        breadcrumbs: [contentCrumb, { title: __('Comments'), href: index() }],
    });
    const description = own
        ? __(
              'Readers’ comments on your posts and pages, newest first. Approving, marking as spam and moving to the trash can each be undone.',
          )
        : __(
              'Readers’ comments, newest first. Approving, marking as spam and moving to the trash can each be undone.',
          );

    // One request for a moves list; the server answers with the list as it
    // was and a toast that offers Undo.
    const move = (moves: { id: string; to: string }[]) =>
        router.post(
            moderate().url,
            { moves },
            {
                preserveScroll: true,
                onSuccess: () => setSelected([]),
            },
        );
    const act = (ids: string[], action: Move) =>
        action.to === 'delete'
            ? setDeleting(ids)
            : move(ids.map((id) => ({ id, to: action.to })));

    // The keys act through the same requests as the line and the menu.
    const keys = useCommentKeys(rows, {
        move: (row, to) => move([{ id: row.id, to }]),
        toggle: (row) =>
            setSelected(
                chosen.includes(row.id)
                    ? chosen.filter((id) => id !== row.id)
                    : [...chosen, row.id],
            ),
        reply: (row) => setReplying(row.id),
        quickEdit: (row) => setEditing(row),
    });

    // The drawer is its own address, read again alone.
    const openDetails = (id: string | null) => {
        const url = new URL(window.location.href);

        if (id === null) {
            url.searchParams.delete('comment');
        } else {
            url.searchParams.set('comment', id);
        }

        router.get(
            `${url.pathname}${url.search}`,
            {},
            { only: ['details'], preserveState: true, preserveScroll: true },
        );
    };

    const entry = (row: CommentRow, action: Move): RowAction => ({
        id: action.to,
        owner: 'core',
        label: action.label,
        destructive: action.destructive,
        onSelect: () => act([row.id], action),
    });

    // The way forward, Reply and Quick edit where they apply, then Details.
    const lineActions = (row: CommentRow): RowAction[] => {
        const first =
            row.status === 'pending'
                ? approve
                : row.status === 'approved'
                  ? unapprove
                  : row.status === 'spam'
                    ? notSpam
                    : restore;
        const writes: RowAction[] = takesReplyAndQuickEdit(row)
            ? [
                  {
                      id: 'reply',
                      owner: 'core',
                      label: __('Reply'),
                      movesFocus: true,
                      onSelect: () => setReplying(row.id),
                  },
                  {
                      id: 'quick-edit',
                      owner: 'core',
                      label: __('Quick edit'),
                      onSelect: () => setEditing(row),
                  },
              ]
            : [];

        return [
            entry(row, first),
            ...writes,
            {
                id: 'details',
                owner: 'core',
                label: __('Details'),
                onSelect: () => openDetails(row.id),
            },
        ];
    };

    // Mark as spam and Move to trash, or in Spam and Trash, Delete permanently.
    const menuActions = (row: CommentRow): RowAction[] =>
        row.status === 'pending' || row.status === 'approved'
            ? [entry(row, spam), entry(row, trash)]
            : [entry(row, remove)];

    const named = (row: CommentRow) =>
        __('the comment by :name', { name: row.authorName });

    const allShown =
        rows.length === 1
            ? __('Select the 1 comment shown')
            : __('Select all :count comments shown', {
                  count: rows.length.toLocaleString(),
              });

    const columns: Column<CommentRow>[] = [
        {
            id: 'select',
            owner: 'core',
            width: 48,
            className: 'pe-0',
            header: (
                <Checkbox
                    aria-label={allShown}
                    disabled={rows.length === 0}
                    checked={
                        rows.length > 0 &&
                        rows.every((row) => chosen.includes(row.id))
                    }
                    onCheckedChange={(checked) =>
                        setSelected(checked ? rows.map((row) => row.id) : [])
                    }
                />
            ),
            cell: (row) => (
                <Checkbox
                    aria-label={__('Select the comment by :name', {
                        name: row.authorName,
                    })}
                    checked={chosen.includes(row.id)}
                    onCheckedChange={(checked) =>
                        setSelected(
                            checked
                                ? [...chosen, row.id]
                                : chosen.filter((id) => id !== row.id),
                        )
                    }
                />
            ),
        },
        {
            id: 'author',
            owner: 'core',
            header: __('Author'),
            minWidth: 176,
            cell: (row) => (
                <Commenter
                    row={row}
                    seesAddresses={seesAddresses}
                    onFindAddress={(ip) => visit({ view, search: ip })}
                />
            ),
        },
        {
            id: 'comment',
            owner: 'core',
            header: __('Comment'),
            minWidth: 256,
            cell: (row) => (
                <div className="grid gap-1">
                    {row.parent && (
                        <p className="text-small text-ink-3">
                            {row.parent.removed ||
                            row.parent.authorName === null
                                ? __('In reply to a removed comment')
                                : __('In reply to :name', {
                                      name: row.parent.authorName,
                                  })}
                        </p>
                    )}
                    <span>
                        <CommentStatusPill status={row.status} />
                    </span>
                    <p className="whitespace-pre-line">{row.text}</p>
                    <RowLine title={named(row)} items={lineActions(row)} />
                    {replying === row.id && (
                        <InlineReply
                            row={row}
                            onClose={(posted) => {
                                setReplying(null);

                                if (posted) {
                                    keys.refocusRow();
                                } else {
                                    keys.focusRow(row.id);
                                }
                            }}
                        />
                    )}
                </div>
            ),
        },
        {
            id: 'item',
            owner: 'core',
            header: __('In response to'),
            cell: (row) => (
                <div className="grid justify-items-start gap-0.5">
                    {row.item.editable ? (
                        <Link
                            href={edit(row.item.id)}
                            className="focus-visible:outline-ring rounded-sm font-semibold hover:underline focus-visible:outline-2 focus-visible:outline-offset-2"
                        >
                            {shownTitle(row.item.title)}
                        </Link>
                    ) : (
                        <span className="font-semibold">
                            {shownTitle(row.item.title)}
                        </span>
                    )}
                    {item === null && (
                        <TextButton
                            className="text-small"
                            onClick={() => visit({ view, item: row.item.id })}
                        >
                            {__n(
                                ':count comment on it|:count comments on it',
                                row.item.comments,
                            )}
                        </TextButton>
                    )}
                </div>
            ),
        },
        {
            id: 'submitted',
            owner: 'core',
            header: __('Submitted'),
            className: 'whitespace-nowrap',
            cell: (row) => (
                <span className="grid">
                    <span>{formatDate(row.createdAt, siteTimezone)}</span>
                    <span className="text-small text-ink-3 tabular-nums">
                        {formatTime(row.createdAt, siteTimezone)}
                    </span>
                </span>
            ),
        },
        {
            id: 'menu',
            owner: 'core',
            width: 56,
            className: 'px-2',
            header: <span className="sr-only">{__('Actions')}</span>,
            cell: (row) => (
                <RowMenu title={named(row)} items={menuActions(row)} />
            ),
        },
    ];

    const filtered = filters.search !== undefined || item !== null;
    const active = (filters.search !== undefined ? 1 : 0) + (item ? 1 : 0);

    const empty: Record<CommentView, { title: string; line: string }> = {
        all: {
            title: __('No comments yet'),
            line: __('Readers’ comments appear here as they arrive.'),
        },
        pending: {
            title: __('Nothing is waiting'),
            line: __('Comments held for approval appear here.'),
        },
        approved: {
            title: __('No approved comments'),
            line: __('Comments readers can see appear here.'),
        },
        spam: {
            title: __('No spam'),
            line: __('Comments marked as spam wait here until you empty spam.'),
        },
        trash: {
            title: __('The trash is empty'),
            line:
                trashDays === 1
                    ? __(
                          'Comments you move to the trash wait here for 1 day, so you can restore them.',
                      )
                    : __(
                          'Comments you move to the trash wait here for :days days, so you can restore them.',
                          { days: trashDays.toLocaleString() },
                      ),
        },
    };

    // J and K, then each key and what it does.
    const keyHints: [string, string][] = [
        ['J', __('and')],
        ['K', __('move between comments,')],
        ['X', __('selects,')],
        ['A', __('approves,')],
        ['U', __('unapproves,')],
        ['S', __('marks spam,')],
        ['T', __('moves to trash,')],
        ['R', __('replies,')],
        ['Q', __('opens Quick edit.')],
    ];

    const [buttons, more] = (() => {
        const moves = bulkMoves[view];
        const kept = moves.filter((action) => action.to !== 'delete');

        return [
            kept.slice(0, 3),
            [
                ...kept.slice(3),
                ...moves.filter((action) => action.to === 'delete'),
            ],
        ];
    })();

    const emptiable =
        (view === 'spam' || view === 'trash') &&
        emptying !== null &&
        emptying.count > 0;

    return (
        <ListPage
            title={__('Comments')}
            description={description}
            actions={
                emptiable && (
                    <Button
                        variant="danger"
                        type="button"
                        onClick={() => setEmptyingOpen(true)}
                    >
                        {view === 'spam' ? __('Empty spam') : __('Empty trash')}
                    </Button>
                )
            }
        >
            <Head title={__('Comments')} />

            {/* Its own grid cell may be narrower than its views, which then scroll sideways inside it (I-3422). */}
            <div className="min-w-0">
                <InPageNav
                    label={__('Views')}
                    items={views.map((tab) => ({
                        label: tab.label,
                        count: tab.count,
                        current: tab.key === view,
                        href: index({ query: { ...keep, view: tab.key } }).url,
                    }))}
                />
            </div>

            <FilterRow
                active={active}
                search={
                    <form
                        role="search"
                        className="flex w-full items-center gap-2 sm:w-auto"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const typed = new FormData(event.currentTarget).get(
                                'search',
                            );
                            const search =
                                typeof typed === 'string' ? typed.trim() : '';

                            visit({
                                view,
                                ...(item ? { item: item.id } : {}),
                                ...(search ? { search } : {}),
                            });
                        }}
                    >
                        <SearchField
                            key={filters.search ?? ''}
                            name="search"
                            defaultValue={filters.search ?? ''}
                            placeholder={
                                seesAddresses
                                    ? __('Text, name, email or IP address')
                                    : __('Text, name or email')
                            }
                            aria-label={__('Search comments')}
                            className="min-w-0 flex-1 sm:w-70 sm:flex-none"
                        />
                        <Button variant="neutral" type="submit">
                            {__('Search')}
                        </Button>
                    </form>
                }
                filters={
                    item ? (
                        <div className="flex items-center gap-1">
                            <StatusPill tone="neutral" flat>
                                {__('On “:title”', {
                                    title: shownTitle(item.title),
                                })}
                            </StatusPill>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-xs"
                                aria-label={__('Show comments on every item')}
                                onClick={() =>
                                    visit({
                                        view,
                                        ...(filters.search
                                            ? { search: filters.search }
                                            : {}),
                                    })
                                }
                            >
                                <X aria-hidden="true" />
                            </Button>
                        </div>
                    ) : undefined
                }
                clear={
                    filtered && (
                        <Button
                            variant="ghost"
                            type="button"
                            onClick={() => visit({ view })}
                        >
                            {__('Clear filters')}
                        </Button>
                    )
                }
            />

            {chosen.length > 0 && (
                <BulkBar
                    count={chosen.length}
                    noun={{ one: __('comment'), many: __('comments') }}
                    onClear={() => setSelected([])}
                    more={more.map((action) => ({
                        id: action.to,
                        owner: 'core',
                        label: action.label,
                        destructive: action.destructive,
                        onSelect: () => act(chosen, action),
                    }))}
                >
                    {buttons.map((action) => (
                        <Button
                            key={action.to}
                            type="button"
                            size="sm"
                            variant="neutral"
                            className="bg-surface"
                            onClick={() => act(chosen, action)}
                        >
                            {action.label}
                        </Button>
                    ))}
                </BulkBar>
            )}

            <DataTable
                columns={columns}
                rows={rows}
                rowKey={(row) => row.id}
                caption={__('Comments')}
                describedBy="cm-keys"
                rowProps={keys.rowProps}
                selectedKeys={new Set(chosen)}
                pinned={{ start: ['select', 'author'], end: ['menu'] }}
                cards={{
                    titleColumn: 'author',
                    select: 'select',
                    menu: 'menu',
                    body: 'comment',
                    facts: ['item', 'submitted'],
                    selectLabel: allShown,
                }}
                footer={
                    <NumberedPager
                        pages={paging}
                        perPage={perPage}
                        href={(query) =>
                            index({ query: { ...keep, view, ...query } }).url
                        }
                    />
                }
                empty={
                    filtered ? (
                        <EmptyState
                            kind="nothing-matches"
                            title={
                                filters.search
                                    ? __('No comments match “:search”', {
                                          search: filters.search,
                                      })
                                    : __('No comments match these filters')
                            }
                            clearHref={index({ query: { view } }).url}
                        />
                    ) : (
                        <EmptyState
                            kind="nothing-yet"
                            title={empty[view].title}
                            description={empty[view].line}
                        />
                    )
                }
            />

            <p id="cm-keys" className="text-small text-ink-3">
                {keyHints.map(([key, words]) => (
                    <span key={key}>
                        <Kbd>{key}</Kbd> {words}{' '}
                    </span>
                ))}
            </p>

            <CommentDetailsDrawer
                details={details}
                seesAddresses={seesAddresses}
                siteTimezone={siteTimezone}
                onClose={() => openDetails(null)}
                onMove={(to) => details && move([{ id: details.id, to }])}
                onDelete={() => details && setDeleting([details.id])}
                onQuickEdit={() => {
                    if (details) {
                        openDetails(null);
                        setEditing(details);
                    }
                }}
            />

            <QuickEdit
                key={editing?.id ?? 'none'}
                comment={editing}
                onClose={() => setEditing(null)}
            />

            {(view === 'spam' || view === 'trash') && emptying !== null && (
                <EmptyComments
                    open={emptyingOpen}
                    onOpenChange={setEmptyingOpen}
                    view={view}
                    facts={emptying}
                    item={item}
                    search={filters.search ?? null}
                    own={own}
                    openedAt={openedAt}
                />
            )}

            <DeleteComments
                rows={rows}
                details={details}
                ids={deleting ?? []}
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                onDeleted={() => setSelected([])}
            />
        </ListPage>
    );
}
