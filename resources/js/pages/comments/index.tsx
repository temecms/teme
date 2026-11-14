import { Head, Link, router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import { CommentDetailsDrawer } from '@/components/comments/comment-details';
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
import { PageHeader } from '@/components/list/page-header';
import { Pager } from '@/components/list/pager';
import { RowLinks } from '@/components/list/row-links';
import type { RowAction } from '@/components/list/row-actions';
import { TabStrip } from '@/components/list/tab-strip';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { formatInZone } from '@/lib/zoned-time';
import { index, moderate } from '@/routes/comments';
import { edit } from '@/routes/content';
import type { CommentRow, CommentsIndexPage, CommentView } from '@/types';
import { shownTitle } from '@/lib/titles';

/** One move a button makes: the status it sends, or `delete` for the confirmation. */
type Move = { label: string; to: string; destructive?: boolean };

const SPAM: Move = { label: 'Mark as spam', to: 'spam' };
const TRASH: Move = { label: 'Move to trash', to: 'trash', destructive: true };
const DELETE: Move = {
    label: 'Delete permanently',
    to: 'delete',
    destructive: true,
};

/** The bulk bar's buttons by view (v9's commentBulkActions). */
const BULK: Record<CommentView, Move[]> = {
    all: [
        { label: 'Approve', to: 'approved' },
        { label: 'Unapprove', to: 'pending' },
        SPAM,
        TRASH,
    ],
    pending: [{ label: 'Approve', to: 'approved' }, SPAM, TRASH],
    approved: [{ label: 'Unapprove', to: 'pending' }, SPAM, TRASH],
    spam: [{ label: 'Mark as not spam', to: 'restore' }, DELETE],
    trash: [{ label: 'Restore', to: 'restore' }, DELETE],
};

/** What each view says when it has no comments (v9's EMPTY_COMMENTS; question 4). */
const EMPTY: Record<CommentView, { title: string; line: string }> = {
    all: {
        title: 'No comments yet',
        line: 'Readers’ comments appear here as they arrive.',
    },
    pending: {
        title: 'Nothing is waiting',
        line: 'Comments held for approval appear here.',
    },
    approved: {
        title: 'No approved comments',
        line: 'Comments readers can see appear here.',
    },
    spam: {
        title: 'No spam',
        line: 'Comments marked as spam wait here until you empty spam.',
    },
    // The trash's words carry its days (D18), so they are built where they're drawn.
    trash: { title: 'The trash is empty', line: '' },
};

/** The keys' hint, a letter and what it does (C11; v9's #cm-keys): each letter a `<kbd>`. */
const KEYS: [string, string][] = [
    ['J', ' and '],
    ['K', ' move between comments,'],
    ['X', ' selects,'],
    ['A', ' approves,'],
    ['U', ' unapproves,'],
    ['S', ' marks spam,'],
    ['T', ' moves to trash,'],
    ['R', ' replies,'],
    ['Q', ' opens Quick edit'],
];

/**
 * The Comments screen (1.8c, v9's SCREENS.comments): views with counts, a
 * search, the list narrowed to an item, a page of comments to moderate one at
 * a time or in bulk, with Undo, and a drawer for one comment's details; Quick
 * edit, the inline reply, Empty spam and Empty trash, and the keys (1.8c′).
 */
export default function CommentsIndex(page: CommentsIndexPage) {
    const {
        view,
        views,
        rows,
        newer,
        older,
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
    const [selected, setSelected] = useState<string[]>([]);
    const [deleting, setDeleting] = useState<string[] | null>(null);
    const [replying, setReplying] = useState<string | null>(null);
    const [editing, setEditing] = useState<QuickEditTarget | null>(null);
    const [emptyingOpen, setEmptyingOpen] = useState(false);

    // Only what the page holds stays selected, and a new view, item, search
    // or page starts with nothing selected.
    const pageKey = [
        view,
        item?.id ?? '',
        filters.search ?? '',
        older ?? '',
        newer ?? '',
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
    const description = own
        ? 'Readers’ comments on your posts and pages, newest first. Approving, marking as spam and moving to the trash can each be undone.'
        : 'Readers’ comments, newest first. Approving, marking as spam and moving to the trash can each be undone.';

    // One request for a moves list; the server answers with the list as it
    // was and a toast that offers Undo (D10, D11, D23).
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

    // The keys act through the same requests as the menu (D14).
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

    // The drawer is its own address (C7), read again alone (D13).
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

    const rowMoves = (row: CommentRow): Move[] => {
        switch (row.status) {
            case 'pending':
                return [
                    { label: 'Approve', to: 'approved' },
                    { label: 'Mark as spam', to: 'spam' },
                    TRASH,
                ];
            case 'approved':
                return [
                    { label: 'Unapprove', to: 'pending' },
                    { label: 'Mark as spam', to: 'spam' },
                    TRASH,
                ];
            case 'spam':
                return [{ label: 'Mark as not spam', to: 'restore' }, DELETE];
            default:
                return [{ label: 'Restore', to: 'restore' }, DELETE];
        }
    };

    // v9's order: the way forward, Details, then the rest (D26).
    const rowActions = (row: CommentRow): RowAction[] => {
        const [first, ...rest] = rowMoves(row);
        const entry = (action: Move): RowAction => ({
            id: action.to,
            owner: 'core',
            label: action.label,
            destructive: action.destructive,
            onSelect: () => act([row.id], action),
        });

        const writes: RowAction[] = takesReplyAndQuickEdit(row)
            ? [
                  {
                      id: 'reply',
                      owner: 'core',
                      label: 'Reply',
                      movesFocus: true,
                      onSelect: () => setReplying(row.id),
                  },
                  {
                      id: 'quick-edit',
                      owner: 'core',
                      label: 'Quick edit',
                      onSelect: () => setEditing(row),
                  },
              ]
            : [];

        return [
            entry(first),
            ...writes,
            {
                id: 'details',
                owner: 'core',
                label: 'Details',
                onSelect: () => openDetails(row.id),
            },
            ...rest.map(entry),
        ];
    };

    const columns: Column<CommentRow>[] = [
        {
            id: 'select',
            owner: 'core',
            className: 'w-10 pr-0',
            header: (
                <Checkbox
                    aria-label={
                        rows.length === 1
                            ? 'Select the 1 comment shown'
                            : `Select all ${rows.length.toLocaleString()} comments shown`
                    }
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
                    aria-label={`Select the comment by ${row.authorName}`}
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
            header: 'Author',
            className: 'min-w-44',
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
            header: 'Comment',
            className: 'min-w-64',
            cell: (row) => (
                <div className="space-y-1">
                    {row.parent && (
                        <p className="text-muted-foreground text-[12px]">
                            {row.parent.removed ||
                            row.parent.authorName === null
                                ? 'In reply to a removed comment'
                                : `In reply to ${row.parent.authorName}`}
                        </p>
                    )}
                    {view === 'all' && row.status === 'pending' && (
                        <Badge variant="secondary">Pending</Badge>
                    )}
                    <p className="whitespace-pre-line">{row.text}</p>
                    <RowLinks
                        label={`Actions for the comment by ${row.authorName}`}
                        more={`More actions for the comment by ${row.authorName}`}
                        items={rowActions(row)}
                    />
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
            header: 'In response to',
            cell: (row) =>
                row.item.editable ? (
                    <Link href={edit(row.item.id)} className="hover:underline">
                        {shownTitle(row.item.title)}
                    </Link>
                ) : (
                    shownTitle(row.item.title)
                ),
        },
        {
            id: 'submitted',
            owner: 'core',
            header: 'Submitted',
            className: 'whitespace-nowrap',
            cell: (row) => formatInZone(row.createdAt, siteTimezone),
        },
    ];

    const filtered = filters.search !== undefined || item !== null;
    const empty =
        view === 'trash'
            ? {
                  ...EMPTY.trash,
                  line: `Comments you move to the trash wait here for ${trashDays === 1 ? '1 day' : `${trashDays.toLocaleString()} days`}, so you can restore them.`,
              }
            : EMPTY[view];

    return (
        <>
            <Head title="Comments" />

            <div className="space-y-4 p-6">
                <PageHeader title="Comments" description={description} />

                <TabStrip
                    label="Views"
                    current={view}
                    tabs={views.map((tab) => ({
                        key: tab.key,
                        label: tab.label,
                        count: tab.count,
                        href: index({ query: { ...keep, view: tab.key } }),
                    }))}
                />

                <form
                    role="search"
                    className="flex flex-wrap items-center gap-2"
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
                    <Input
                        key={filters.search ?? ''}
                        name="search"
                        defaultValue={filters.search ?? ''}
                        placeholder={
                            seesAddresses
                                ? 'Text, name, email or IP address'
                                : 'Text, name or email'
                        }
                        aria-label="Search comments"
                        className="w-72"
                    />
                    <Button variant="outline" type="submit">
                        Search
                    </Button>
                    {item && (
                        <Badge variant="secondary" className="h-7 gap-1 pr-1">
                            {`On “${shownTitle(item.title)}”`}
                            <button
                                type="button"
                                aria-label="Show comments on every item"
                                className="hover:bg-accent focus-visible:ring-ring inline-flex size-6 items-center justify-center rounded-sm focus-visible:ring-2 focus-visible:outline-none"
                                onClick={() =>
                                    visit({
                                        view,
                                        ...(filters.search
                                            ? { search: filters.search }
                                            : {}),
                                    })
                                }
                            >
                                <X aria-hidden className="size-3" />
                            </button>
                        </Badge>
                    )}
                    {filtered && (
                        <Button
                            variant="ghost"
                            type="button"
                            onClick={() => visit({ view })}
                        >
                            Clear filters
                        </Button>
                    )}
                    {(view === 'spam' || view === 'trash') &&
                        emptying !== null &&
                        emptying.count > 0 && (
                            <Button
                                variant="destructive"
                                type="button"
                                className="ml-auto"
                                onClick={() => setEmptyingOpen(true)}
                            >
                                {view === 'spam' ? 'Empty spam' : 'Empty trash'}
                            </Button>
                        )}
                </form>

                <p id="cm-keys" className="text-muted-foreground text-[12px]">
                    {'Keyboard: '}
                    {KEYS.map(([key, words], at) => (
                        <span key={key}>
                            <kbd className="border-border rounded-sm border px-1 font-mono text-[11px]">
                                {key}
                            </kbd>
                            {words}
                            {at === KEYS.length - 1
                                ? '.'
                                : words.endsWith(',')
                                  ? ' '
                                  : ''}
                        </span>
                    ))}
                </p>

                {chosen.length > 0 && (
                    <BulkBar
                        count={chosen.length}
                        onClear={() => setSelected([])}
                    >
                        {BULK[view].map((action) => (
                            <Button
                                key={action.to}
                                size="sm"
                                variant={
                                    action.destructive
                                        ? 'destructive'
                                        : 'outline'
                                }
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
                    caption="Comments"
                    describedBy="cm-keys"
                    rowProps={keys.rowProps}
                    empty={
                        filtered ? (
                            <EmptyState
                                kind="nothing-matches"
                                title={
                                    filters.search
                                        ? `No comments match “${filters.search}”`
                                        : 'No comments match these filters'
                                }
                                clearHref={index({ query: { view } }).url}
                            />
                        ) : (
                            <EmptyState
                                kind="nothing-yet"
                                title={empty.title}
                                description={empty.line}
                            />
                        )
                    }
                />

                <Pager
                    newer={
                        newer
                            ? index({ query: { ...keep, view, before: newer } })
                                  .url
                            : null
                    }
                    older={
                        older
                            ? index({ query: { ...keep, view, after: older } })
                                  .url
                            : null
                    }
                />
            </div>

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
        </>
    );
}
