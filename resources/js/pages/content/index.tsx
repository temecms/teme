import { Form, Head, Link, router, useHttp, usePage } from '@inertiajs/react';
import { CircleAlert, Image as ImageIcon, X } from 'lucide-react';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import BulkContentController from '@/actions/App/Http/Admin/Contents/BulkContentController';
import BulkMatchingController from '@/actions/App/Http/Admin/Contents/BulkMatchingController';
import ContentActionController from '@/actions/App/Http/Admin/Contents/ContentActionController';
import ContentListController from '@/actions/App/Http/Admin/Contents/ContentListController';
import ReviewController from '@/actions/App/Http/Admin/Contents/ReviewController';
import { CommentsCell } from '@/components/comments/comments-cell';
import { ConfirmAction } from '@/components/confirm-action';
import InputError from '@/components/input-error';
import { BulkBar } from '@/components/list/bulk-bar';
import { BulkEditDialog } from '@/components/list/bulk-edit-dialog';
import type { BulkEditReport } from '@/components/list/bulk-edit-dialog';
import { ConfirmItems } from '@/components/list/confirm-items';
import { DataTable } from '@/components/list/data-table';
import { DateFilter } from '@/components/list/date-filter';
import { FilterButton } from '@/components/list/filter-button';
import type { Column, SortDirection } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { PageHeader } from '@/components/list/page-header';
import { Pager } from '@/components/list/pager';
import { RowLinks } from '@/components/list/row-links';
import { ScreenOptions } from '@/components/list/screen-options';
import {
    ConfirmMatching,
    SelectAllMatching,
} from '@/components/list/select-all-matching';
import type { MatchingAction } from '@/components/list/select-all-matching';
import type { RowAction } from '@/components/list/row-actions';
import { TabStrip } from '@/components/list/tab-strip';
import { MediaModal } from '@/components/media/media-modal';
import { TermPicker } from '@/components/term-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { choiceOf, DISCUSSION_CHOICES } from '@/lib/discussion';
import type { Discussion } from '@/lib/discussion';
import { inTreeOrder } from '@/lib/term-tree';
import { shownTitle } from '@/lib/titles';
import { formatInZone } from '@/lib/zoned-time';
import {
    edit as contentEdit,
    index as contentIndex,
    preview as contentPreview,
} from '@/routes/content';
import { show as showMedia } from '@/routes/media';
import type {
    ContentIndexPage,
    ContentRow,
    FeaturedImage,
    MediaDetailsData,
    TermChip,
} from '@/types';

function QuickEdit({
    row,
    page,
    onClose,
}: {
    row: ContentRow;
    page: ContentIndexPage;
    onClose: () => void;
}) {
    const { submit } = useHttp();
    const [terms, setTerms] = useState<Record<string, TermChip[]>>(() =>
        Object.fromEntries(
            page.typeTaxonomies.map((taxonomy) => [
                taxonomy.key,
                row.terms[taxonomy.key] ?? [],
            ]),
        ),
    );
    const [image, setImage] = useState<FeaturedImage | null>(row.featuredImage);
    const [discussion, setDiscussion] = useState<Discussion>(
        row.discussion?.value ?? 'open',
    );
    const termCount = Object.values(terms).reduce(
        (total, chosen) => total + chosen.length,
        0,
    );
    const [picking, setPicking] = useState(false);

    return (
        <Form
            {...ContentActionController.details.form({
                type: page.type,
                content: row.id,
            })}
            onSuccess={onClose}
            className="grid gap-4 px-4 pb-4"
        >
            {({ processing, errors }) => (
                <>
                    <input
                        type="hidden"
                        name="expected_revision_id"
                        value={row.workingRevisionId ?? ''}
                    />
                    <div className="grid gap-2">
                        <Label htmlFor={`title-${row.id}`}>Title</Label>
                        <Input
                            id={`title-${row.id}`}
                            aria-describedby={`title-${row.id}-error`}
                            name="title"
                            defaultValue={row.title}
                            required
                        />
                        <InputError
                            id={`title-${row.id}-error`}
                            message={errors.title}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`slug-${row.id}`}>Address</Label>
                        <Input
                            id={`slug-${row.id}`}
                            aria-describedby={`slug-${row.id}-error`}
                            name="slug"
                            defaultValue={row.slug}
                        />
                        {row.status === 'published' && (
                            <p className="text-ink-2 text-xs">
                                Changing a published address takes effect at
                                once, and the old one stops working.
                            </p>
                        )}
                        <InputError
                            id={`slug-${row.id}-error`}
                            message={errors.slug}
                        />
                    </div>
                    {page.can.changeAuthor && (
                        <div className="grid gap-2">
                            <Label htmlFor={`author-${row.id}`}>Author</Label>
                            <SelectField
                                id={`author-${row.id}`}
                                name="author_id"
                                // With no author, the first one, as the native select picked.
                                defaultValue={String(
                                    row.authorId ??
                                        page.authors[0]?.value ??
                                        '',
                                )}
                                options={page.authors}
                            />
                        </div>
                    )}
                    {row.status === 'published' && (
                        <div className="grid gap-2">
                            <Label htmlFor={`date-${row.id}`}>Published</Label>
                            <Input
                                id={`date-${row.id}`}
                                aria-describedby={`date-${row.id}-error`}
                                type="datetime-local"
                                name="published_at"
                                defaultValue={
                                    row.publishedAt?.slice(0, 16) ?? ''
                                }
                            />
                            <InputError
                                id={`date-${row.id}-error`}
                                message={errors.published_at}
                            />
                        </div>
                    )}
                    {row.discussion !== null && (
                        <div className="grid gap-2">
                            <Label htmlFor={`discussion-${row.id}`}>
                                Discussion
                            </Label>
                            <SelectField
                                id={`discussion-${row.id}`}
                                aria-describedby={`discussion-${row.id}-meaning discussion-${row.id}-error`}
                                // Sent only when changed, so a form opened
                                // before someone else's change can't undo it.
                                name={
                                    row.discussion.canSet &&
                                    discussion !== row.discussion.value
                                        ? 'discussion'
                                        : undefined
                                }
                                value={discussion}
                                onValueChange={(next) =>
                                    setDiscussion(next as Discussion)
                                }
                                disabled={!row.discussion.canSet}
                                options={DISCUSSION_CHOICES.map((choice) => ({
                                    value: choice.value,
                                    label: choice.label,
                                }))}
                            />
                            <p
                                id={`discussion-${row.id}-meaning`}
                                className="text-ink-2 text-xs"
                            >
                                {choiceOf(discussion).meaning}
                                {!row.discussion.canSet &&
                                    ' Whoever publishes it sets this.'}
                            </p>
                            <InputError
                                id={`discussion-${row.id}-error`}
                                message={errors.discussion}
                            />
                        </div>
                    )}
                    {page.typeTaxonomies.map((taxonomy) => (
                        <TermPicker
                            key={taxonomy.key}
                            taxonomy={taxonomy.key}
                            singular={taxonomy.singular}
                            label={taxonomy.plural}
                            value={terms[taxonomy.key] ?? []}
                            onChange={(value) =>
                                setTerms((current) => ({
                                    ...current,
                                    [taxonomy.key]: value,
                                }))
                            }
                        />
                    ))}
                    <div className="grid gap-2">
                        <span className="text-sm font-medium">
                            Featured image
                        </span>
                        <input
                            type="hidden"
                            name="featured_image_id"
                            value={image?.id ?? ''}
                        />
                        <div className="flex items-center gap-3">
                            {image?.thumbUrl ? (
                                <img
                                    src={image.thumbUrl}
                                    alt=""
                                    className="size-16 rounded object-cover"
                                />
                            ) : (
                                <span className="text-ink-2 text-sm">None</span>
                            )}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                aria-describedby={`image-${row.id}-error`}
                                onClick={() => setPicking(true)}
                            >
                                {image ? 'Change…' : 'Choose…'}
                            </Button>
                            {image && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setImage(null)}
                                >
                                    Remove
                                </Button>
                            )}
                        </div>
                        {image && (
                            <div className="grid gap-2">
                                <div className="grid gap-1">
                                    <Label htmlFor={`alt-${row.id}`}>
                                        Alt text for this{' '}
                                        {page.labels.singular.toLowerCase()}
                                    </Label>
                                    <Input
                                        id={`alt-${row.id}`}
                                        name="featured_image_alt"
                                        defaultValue={image.alt ?? ''}
                                        placeholder="Leave empty to use the image's own"
                                    />
                                </div>
                            </div>
                        )}
                        <InputError
                            id={`image-${row.id}-error`}
                            message={
                                errors[`fields.01K5Z0F1ED00000000000000F6`]
                            }
                        />
                        <MediaModal
                            open={picking}
                            onOpenChange={setPicking}
                            kinds={['image']}
                            action="Set featured image"
                            onChoose={([id]) => {
                                if (!id) {
                                    return;
                                }

                                void submit(showMedia({ media: id })).then(
                                    (details) => {
                                        const item =
                                            details as MediaDetailsData;
                                        setImage({
                                            id: item.id,
                                            title: item.title,
                                            thumbUrl: item.thumbUrl,
                                            alt: null,
                                            decorative: false,
                                        });
                                    },
                                );
                            }}
                        />
                    </div>
                    {/* Sent when every term is removed, so clearing them
                        saves. A type with no taxonomies sends no terms at all,
                        so its kept terms stay as they are (I-417). */}
                    {page.typeTaxonomies.length > 0 && termCount === 0 && (
                        <input type="hidden" name="terms" value="" />
                    )}
                    <div className="flex items-center gap-2">
                        <Button
                            disabled={processing}
                            aria-describedby={`quick-edit-${row.id}-error`}
                        >
                            Save
                        </Button>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <InputError
                            id={`quick-edit-${row.id}-error`}
                            message={errors.expected_revision_id}
                        />
                    </div>
                </>
            )}
        </Form>
    );
}

/** A row's title for a sentence: "Untitled" when it has none. */
const titleOf = (row: ContentRow): string => row.title.trim() || 'Untitled';

/** The words after a title for each state that isn't live. */
const STATUS_SUFFIX: Record<'draft' | 'review' | 'scheduled', string> = {
    draft: 'Draft',
    review: 'In review',
    scheduled: 'Scheduled',
};

/**
 * A row's state after its title: none for a live item, which its date's
 * line says, unless an update waits for review; none in the trash, which
 * its tab says.
 */
function statusSuffix(row: ContentRow): string | null {
    // Later (I-1451): Sticky, Home page and Posts page, as WordPress lists them.
    if (row.trashed) {
        return null;
    }

    if (row.status === 'published') {
        const who =
            row.visibility === 'private'
                ? 'Private'
                : row.visibility === 'protected'
                  ? 'Password protected'
                  : null;

        if (who === null) {
            return row.review !== null ? 'Update in review' : null;
        }

        return row.review !== null ? `${who}, update in review` : who;
    }

    return STATUS_SUFFIX[row.status];
}

/** What a row can do, from its state and what the viewer may do. */
function rowActions(
    row: ContentRow,
    page: ContentIndexPage,
    quickEdit: () => void,
): RowAction[] {
    const action = (
        name: 'trash' | 'restore' | 'publish' | 'unpublish',
        label: string,
        destructive = false,
    ): RowAction => ({
        id: name,
        owner: 'core',
        label,
        destructive,
        form: ContentActionController.action.form({
            type: page.type,
            content: row.id,
            action: name,
        }),
    });

    if (row.trashed) {
        return [
            action('restore', 'Restore'),
            {
                id: 'delete',
                owner: 'core',
                label: 'Delete permanently',
                destructive: true,
                form: ContentActionController.destroy.form({
                    type: page.type,
                    content: row.id,
                }),
                wrap: (button) => (
                    <ConfirmAction
                        title={`Delete “${titleOf(row)}” permanently?`}
                        description={`“${titleOf(row)}” and all its revisions are deleted. This can’t be undone.`}
                        confirmLabel="Delete permanently"
                    >
                        {button}
                    </ConfirmAction>
                ),
            },
        ];
    }

    return [
        {
            id: 'edit',
            owner: 'core',
            label: 'Edit',
            href: contentEdit(row.id).url,
        },
        {
            id: 'quick-edit',
            owner: 'core',
            label: 'Quick edit',
            onSelect: quickEdit,
        },
        // A live item opens at its address; anything else shows its latest
        // saved version, through the theme. Each in a new tab.
        row.viewUrl !== null
            ? {
                  id: 'view',
                  owner: 'core',
                  label: 'View',
                  href: row.viewUrl,
                  newTab: true,
              }
            : {
                  id: 'preview',
                  owner: 'core',
                  label: 'Preview',
                  href: contentPreview(row.id).url,
                  newTab: true,
              },
        // Publishing around a pending review would skip the review the writer
        // asked for: whoever may decide is offered Approve in its place, and
        // anyone else neither.
        ...(row.review?.canDecide
            ? [
                  {
                      id: 'approve',
                      owner: 'core',
                      label: 'Approve',
                      form: ReviewController.approve.form(row.id),
                      fields: { review_id: row.review.id },
                  },
              ]
            : []),
        ...(page.can.publish &&
        row.status !== 'published' &&
        row.review === null
            ? [action('publish', 'Publish')]
            : []),
        ...(page.can.publish && row.status === 'published'
            ? [action('unpublish', 'Revert to draft')]
            : []),
        action('trash', 'Trash', true),
    ];
}

/**
 * When the row happened, or will. A scheduled item says when it publishes,
 * on the site's clock with the zone named, and whether its schedule failed
 * or is held; an item in review says who submitted it and when.
 */
function DateCell({ row, page }: { row: ContentRow; page: ContentIndexPage }) {
    if (row.status === 'scheduled' && row.scheduledFor !== null) {
        return (
            <span className="grid">
                <span className="text-ink-2">
                    {formatInZone(row.scheduledFor, page.siteTimezone)} (
                    {page.siteTimezone})
                </span>
                {row.scheduleIssue === 'failed' && (
                    <span className="text-danger-ink text-xs">Failed</span>
                )}
                {row.scheduleIssue === 'held' && (
                    <span className="text-warn-ink text-xs">Held</span>
                )}
            </span>
        );
    }

    if (row.review !== null) {
        return (
            <span className="text-ink-2 grid">
                <span>
                    Submitted{' '}
                    {formatInZone(row.review.submittedAt, page.siteTimezone)}
                </span>
                <span className="text-xs">
                    by {row.review.submittedBy ?? 'a removed account'}
                </span>
            </span>
        );
    }

    // Live is published and not in the trash: a reverted draft keeps its
    // published date, and an item in the trash keeps its status.
    const live = row.status === 'published' && !row.trashed;

    return (
        <span className="grid">
            <span className="text-ink-2">
                {new Date(
                    live ? (row.publishedAt ?? row.updatedAt) : row.updatedAt,
                ).toLocaleDateString()}
            </span>
            <span className="text-ink-2 text-xs">
                {live ? 'Published' : 'Last modified'}
            </span>
        </span>
    );
}

/** The columns Screen options never offers: they carry the row itself. */
const FIXED_COLUMNS = ['select', 'title'];

export default function ContentIndex(page: ContentIndexPage) {
    const { preferences, contentTypes } = usePage().props;
    // The type's key names its columns preference; the URL carries its segment.
    const typeKey =
        contentTypes.find((type) => type.segment === page.type)?.key ??
        page.type;
    const shownColumns = preferences[`lists.${typeKey}.columns`];
    const [selected, setSelected] = useState<string[]>([]);
    // Every match of the list, not only the page's rows (I-143).
    const [allMatching, setAllMatching] = useState(false);
    const [confirming, setConfirming] = useState<MatchingAction | null>(null);
    // Titles by default; the text is the published revision's (SearchText).
    const [searchIn, setSearchIn] = useState(
        page.filters.in === 'body' ? 'body' : 'title',
    );
    const [editing, setEditing] = useState<string | null>(null);
    const [bulkEditing, setBulkEditing] = useState(false);
    // Bulk Edit sets no flash: its report is the dialog's own, added up
    // from its batches.
    const [editReport, setEditReport] = useState<BulkEditReport | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [bulkBusy, setBulkBusy] = useState(false);
    const [bulkError, setBulkError] = useState<string | null>(null);
    // The one open header panel, so opening one closes any other (I-3135).
    const [openPanel, setOpenPanel] = useState<string | null>(null);
    const searchRef = useRef<HTMLInputElement>(null);
    const isMobile = useIsMobile();
    const panelOpen = (name: string) => (open: boolean) =>
        setOpenPanel((current) =>
            open ? name : current === name ? null : current,
        );

    // Any new rows (a visit, a reload) go back to the page's own selection.
    useEffect(() => setAllMatching(false), [page.rows]);

    // One of this person's jobs ended, perhaps one moving these rows: catch up (I-143).
    useEffect(() => {
        const reload = () => router.reload();

        window.addEventListener('teme:jobs-ended', reload);

        return () => window.removeEventListener('teme:jobs-ended', reload);
    }, []);
    const title = page.labels.plural;
    const activeTab = page.filters.tab ?? 'all';
    const inTrash = activeTab === 'trash';
    const singular = page.labels.singular.toLowerCase();
    const plural = page.labels.plural.toLowerCase();
    // The headers sort the list. Trash keeps its own order, so nothing sorts
    // there; the author sort needs an index Mine and the Author filter can't
    // use (ContentList::sortFor). Date sorts by what the column shows.
    const sortable = activeTab !== 'trash';
    const dateSort =
        activeTab === 'published'
            ? 'published'
            : activeTab === 'scheduled'
              ? 'scheduled'
              : 'updated';
    const sortBy = (key: string) =>
        sortable
            ? { key, direction: page.sort === key ? page.direction : null }
            : undefined;
    const titles = Object.fromEntries(
        page.rows.map((row) => [row.id, row.title]),
    );
    const chosenTitles = page.rows
        .filter((row) => selected.includes(row.id))
        .map(titleOf);

    // Each button sends the selection at once; the server runs one
    // Operation per item and reports what it skipped (I-143).
    const bulk = (action: 'trash' | 'restore' | 'delete') =>
        router.post(
            BulkContentController.url({ type: page.type }),
            {
                action,
                ids: selected,
                titles: Object.fromEntries(
                    selected.map((id) => [id, titles[id] ?? '']),
                ),
            },
            {
                preserveScroll: true,
                // Closed as the request starts: the page keeps its state
                // across the visit, so a dialog still fading out after the
                // selection empties would read "Delete 0 posts permanently?".
                onStart: () => {
                    setDeleting(false);
                    setBulkBusy(true);
                    setBulkError(null);
                },
                onFinish: () => setBulkBusy(false),
                onSuccess: () => setSelected([]),
                onError: (errors) =>
                    setBulkError(
                        errors.ids ??
                            errors.action ??
                            Object.values(errors)[0] ??
                            null,
                    ),
            },
        );
    // Select all matching sends the list's filters, never its rows; the
    // server pages through the matches as a job (I-143).
    const startMatching = (action: MatchingAction) =>
        router.post(
            BulkMatchingController.url({ type: page.type }),
            { action, filters: page.filters },
            {
                preserveScroll: true,
                onStart: () => {
                    setBulkBusy(true);
                    setBulkError(null);
                },
                onFinish: () => {
                    setBulkBusy(false);
                    setConfirming(null);
                },
                onSuccess: () => {
                    setSelected([]);
                    setAllMatching(false);
                },
                onError: (errors) =>
                    setBulkError(
                        errors.action ?? Object.values(errors)[0] ?? null,
                    ),
            },
        );
    const scopeCount =
        page.tabs.find((tab) => tab.key === activeTab)?.count ??
        page.tabs[0]?.count ??
        0;
    const keep = Object.fromEntries(
        Object.entries(page.filters).filter(
            ([, value]) => value !== undefined && value !== '',
        ),
    );
    // Each nesting taxonomy gets a select; flat ones, such as tags, can
    // number in thousands, so they wait for a searchable control.
    const nesting = useMemo(
        () => page.typeTaxonomies.filter((taxonomy) => taxonomy.hierarchical),
        [page.typeTaxonomies],
    );
    // A flat taxonomy, such as tags, has no select (I-1452): a filter by one
    // of its terms, as a count on its screen opens, is kept and named.
    const flat = useMemo(
        () => page.typeTaxonomies.filter((taxonomy) => !taxonomy.hierarchical),
        [page.typeTaxonomies],
    );
    const treeOptions = useMemo(
        () =>
            Object.fromEntries(
                nesting.map((taxonomy) => [
                    taxonomy.key,
                    inTreeOrder(page.termTrees[taxonomy.key] ?? []).map(
                        ({ term, depth }) => ({
                            value: term.id,
                            label: term.name,
                            depth,
                        }),
                    ),
                ]),
            ),
        [nesting, page.termTrees],
    );
    // Every filter choice is a link to the list with the others kept: the
    // cursor dropped, order kept, and the tab always written (I-3129).
    const hrefFor = (patch: Record<string, string | null>) => {
        const query: Record<string, string> = { ...keep, tab: activeTab };

        for (const [key, value] of Object.entries(patch)) {
            if (value === null || value === '') {
                delete query[key];
            } else {
                query[key] = value;
            }
        }

        return contentIndex({ type: page.type }, { query }).url;
    };
    const searchTo = (inValue: string) => {
        const words = searchRef.current?.value.trim() ?? '';

        router.visit(
            hrefFor({
                search: words === '' ? null : words,
                in: inValue === 'body' ? 'body' : null,
            }),
        );
    };
    // What narrows the list besides the Date: the months' counts are the tab's, so they say nothing true of it (I-140).
    const narrowedBesideDate = [
        'search',
        'author',
        'review',
        ...page.typeTaxonomies.map((taxonomy) => taxonomy.key),
    ].some((key) => page.filters[key] !== undefined);
    const narrowed =
        narrowedBesideDate ||
        ['month', 'from', 'to'].some((key) => page.filters[key] !== undefined);
    const clearHref = contentIndex(
        { type: page.type },
        {
            query: {
                tab: activeTab,
                ...(page.filters.sort !== undefined
                    ? { sort: page.filters.sort }
                    : {}),
                ...(page.filters.direction !== undefined
                    ? { direction: page.filters.direction }
                    : {}),
            },
        },
    ).url;
    // A new order starts from page one; no direction means the sort's own.
    const sort = (key: string, direction: SortDirection | null) => {
        const { sort: _sort, direction: _direction, ...rest } = keep;

        router.visit(
            contentIndex(
                { type: page.type },
                {
                    query: {
                        ...rest,
                        sort: key,
                        ...(direction ? { direction } : {}),
                    },
                },
            ).url,
        );
    };
    const editingRow = page.rows.find((row) => row.id === editing);
    // The dashboard's See all on its review panels opens the list with this
    // filter, which the In review tab cannot answer: it leaves out a live
    // article whose update waits.
    const reviewFilter =
        page.filters.review === 'pending'
            ? 'Has a pending review'
            : page.filters.review === 'submitted'
              ? 'Submitted by you'
              : undefined;
    const termChips = flat.flatMap((taxonomy) => {
        if (page.filters[taxonomy.key] === undefined) {
            return [];
        }

        const label = `${taxonomy.singular}: ${page.chosenTerms[taxonomy.key] ?? 'not found'}`;

        return [
            {
                key: taxonomy.key,
                label,
                href: contentIndex(
                    { type: page.type },
                    {
                        query: Object.fromEntries(
                            Object.entries(keep).filter(
                                ([key]) => key !== taxonomy.key,
                            ),
                        ),
                    },
                ).url,
            },
        ];
    });
    const emptyKind = page.filtered ? 'nothing-matches' : 'nothing-yet';

    // Other people's unpublished work is listed by title and never acted on
    // (I-409): only the rows this person may edit can be selected.
    const editable = page.rows.filter((row) => row.canEdit);

    const allColumns: Column<ContentRow>[] = [
        {
            id: 'select',
            owner: 'core',
            className: 'w-10 pr-0',
            header: (
                <Checkbox
                    aria-label="Select all on this page"
                    disabled={editable.length === 0}
                    checked={
                        editable.length > 0 &&
                        editable.every((row) => selected.includes(row.id))
                    }
                    onCheckedChange={(checked) => {
                        setAllMatching(false);
                        setSelected(
                            checked ? editable.map((row) => row.id) : [],
                        );
                    }}
                />
            ),
            cell: (row) =>
                row.canEdit && (
                    <Checkbox
                        aria-label={`Select ${shownTitle(row.title)}`}
                        checked={selected.includes(row.id)}
                        onCheckedChange={(checked) => {
                            setAllMatching(false);
                            setSelected(
                                checked
                                    ? [...selected, row.id]
                                    : selected.filter((id) => id !== row.id),
                            );
                        }}
                    />
                ),
        },
        {
            id: 'featured',
            owner: 'core',
            label: 'Featured image',
            className: 'w-[72px] pr-0',
            header: (
                <>
                    <ImageIcon aria-hidden className="size-4" />
                    <span className="sr-only">Featured image</span>
                </>
            ),
            cell: (row) =>
                row.featuredImage?.thumbUrl ? (
                    <img
                        data-test="featured-thumb"
                        src={row.featuredImage.thumbUrl}
                        alt=""
                        loading="lazy"
                        className="h-8 w-12 rounded-sm object-cover"
                    />
                ) : (
                    <span
                        data-test="featured-none"
                        className="border-edge text-ink-2 grid h-8 w-12 place-items-center rounded-sm border border-dashed"
                    >
                        <ImageIcon aria-hidden className="size-3.5" />
                        <span className="sr-only">No featured image</span>
                    </span>
                ),
        },
        {
            id: 'title',
            owner: 'core',
            header: 'Title',
            sort: sortBy('title'),
            className: 'min-w-40',
            // A trashed item has no editor (it answers 404), and someone
            // else's the person may not edit answers 403, so either title
            // stays plain text.
            cell: (row) => {
                const suffix = statusSuffix(row);

                return (
                    <>
                        {row.trashed || !row.canEdit ? (
                            <span
                                data-test="row-title"
                                className="font-semibold"
                            >
                                {shownTitle(row.title)}
                            </span>
                        ) : (
                            <Link
                                href={contentEdit(row.id).url}
                                data-test="row-title"
                                className="font-semibold hover:underline"
                            >
                                {shownTitle(row.title)}
                            </Link>
                        )}
                        {suffix !== null && (
                            <span
                                data-test="status-suffix"
                                className="text-ink-2"
                            >
                                {` — ${suffix}`}
                            </span>
                        )}
                        {/* Not an empty line: a row the person may not edit has none. */}
                        {row.canEdit && (
                            <RowLinks
                                label={`Actions for ${shownTitle(row.title)}`}
                                more={`More actions for ${shownTitle(row.title)}`}
                                items={rowActions(row, page, () =>
                                    setEditing(row.id),
                                )}
                            />
                        )}
                    </>
                );
            },
        },
        {
            id: 'author',
            owner: 'core',
            header: 'Author',
            sort:
                activeTab === 'mine' || page.filters.author !== undefined
                    ? undefined
                    : sortBy('author'),
            cell: (row) => row.authorName ?? '—',
        },
        ...page.typeTaxonomies.map((taxonomy): Column<ContentRow> => ({
            id: `taxonomy-${taxonomy.key}`,
            owner: 'core',
            header: taxonomy.plural,
            cell: (row) => {
                const terms = row.terms[taxonomy.key] ?? [];

                if (terms.length === 0) {
                    return '—';
                }

                if (!taxonomy.hierarchical) {
                    return terms.map((term) => term.name).join(', ');
                }

                return terms.map((term, at) => (
                    <Fragment key={term.id}>
                        {at > 0 && ', '}
                        <Link
                            href={
                                contentIndex(
                                    { type: page.type },
                                    {
                                        query: {
                                            ...keep,
                                            [taxonomy.key]: term.id,
                                        },
                                    },
                                ).url
                            }
                            className="inline-flex min-h-6 min-w-6 items-center hover:underline"
                        >
                            {term.name}
                        </Link>
                    </Fragment>
                ));
            },
        })),
        ...(page.takesComments
            ? [
                  {
                      id: 'comments',
                      owner: 'core',
                      header: 'Comments',
                      className: 'text-right',
                      cell: (row) => (
                          <CommentsCell itemId={row.id} counts={row.comments} />
                      ),
                  } satisfies Column<ContentRow>,
              ]
            : []),
        {
            id: 'date',
            owner: 'core',
            header: 'Date',
            sort: sortBy(dateSort),
            cell: (row) => <DateCell row={row} page={page} />,
        },
    ];
    // The person's Screen options choose which columns show, never where:
    // the table keeps its own order. Without a declaration, every column.
    // Featured image is offered and never on until someone turns it on (I-3120).
    const isShown = (id: string) =>
        id === 'featured'
            ? Array.isArray(shownColumns) && shownColumns.includes(id)
            : !Array.isArray(shownColumns) || shownColumns.includes(id);
    const optionalColumns = allColumns.filter(
        (column) => !FIXED_COLUMNS.includes(column.id),
    );
    const columns = allColumns.filter(
        (column) => FIXED_COLUMNS.includes(column.id) || isShown(column.id),
    );

    return (
        <>
            <Head title={title} />

            <div className="space-y-4 p-6">
                <PageHeader
                    title={title}
                    description="Everything of this kind on the site. A filtered list shows no total."
                    action={
                        <>
                            <ScreenOptions
                                list={typeKey}
                                columns={optionalColumns.map((column) => ({
                                    id: column.id,
                                    // Every optional column is headed by plain text or has a label.
                                    label:
                                        column.label ??
                                        (typeof column.header === 'string'
                                            ? column.header
                                            : column.id),
                                    shown: isShown(column.id),
                                }))}
                                perPage={preferences['lists.per_page']}
                                open={openPanel === 'screen-options'}
                                onOpenChange={panelOpen('screen-options')}
                            />
                            {page.canAdd && (
                                <Form
                                    {...ContentListController.store.form({
                                        type: page.type,
                                    })}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            data-test="add-new"
                                            disabled={processing}
                                        >
                                            {isMobile
                                                ? 'Add new'
                                                : `Add new ${singular}`}
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </>
                    }
                />

                <TabStrip
                    label="Status"
                    current={activeTab}
                    tabs={page.tabs.map((tab) => ({
                        key: tab.key,
                        label: tab.label,
                        count: tab.count,
                        href: contentIndex(
                            { type: page.type },
                            { query: { tab: tab.key } },
                        ),
                    }))}
                />

                <div data-test="list-filters" className="min-w-0">
                    <div className="flex min-w-0 flex-wrap items-center gap-2">
                        <form
                            role="search"
                            onSubmit={(event) => {
                                event.preventDefault();
                                searchTo(searchIn);
                            }}
                            className={
                                isMobile
                                    ? 'flex w-full items-center gap-2'
                                    : 'flex items-center gap-2'
                            }
                        >
                            <Input
                                ref={searchRef}
                                data-test="list-search"
                                name="search"
                                defaultValue={page.filters.search ?? ''}
                                placeholder={
                                    searchIn === 'body'
                                        ? 'Search text'
                                        : 'Search titles'
                                }
                                aria-label={
                                    searchIn === 'body'
                                        ? 'Search text'
                                        : 'Search titles'
                                }
                                className="h-8 min-w-0 flex-1 sm:w-72 sm:flex-none"
                            />
                            <SelectField
                                name="in"
                                value={searchIn}
                                onValueChange={(next) => {
                                    setSearchIn(next);

                                    // Typed words search at once in the place chosen; else it goes with the next search.
                                    if (
                                        (searchRef.current?.value ?? '') !== ''
                                    ) {
                                        searchTo(next);
                                    }
                                }}
                                aria-label="Search in"
                                aria-describedby="search-in-hint"
                                options={[
                                    { value: 'title', label: 'In titles' },
                                    { value: 'body', label: 'In text' },
                                ]}
                            />
                            {searchIn === 'body' && (
                                <span
                                    id="search-in-hint"
                                    className="text-ink-2 text-xs"
                                >
                                    Published text only
                                </span>
                            )}
                        </form>
                        <div
                            data-test={isMobile ? 'filter-chips' : undefined}
                            className={
                                isMobile
                                    ? 'flex w-full min-w-0 gap-2 overflow-x-auto pb-1'
                                    : 'flex flex-wrap items-center gap-2'
                            }
                        >
                            <FilterButton
                                name="author"
                                label="Author"
                                value={
                                    page.authors.find(
                                        (author) =>
                                            String(author.value) ===
                                            page.filters.author,
                                    )?.label ?? 'All'
                                }
                                active={page.filters.author !== undefined}
                                open={openPanel === 'author'}
                                onOpenChange={panelOpen('author')}
                                choices={[
                                    {
                                        key: 'all',
                                        label: 'All authors',
                                        href: hrefFor({ author: null }),
                                        current:
                                            page.filters.author === undefined,
                                    },
                                    ...page.authors.map((author) => ({
                                        key: String(author.value),
                                        label: author.label,
                                        href: hrefFor({
                                            author: String(author.value),
                                        }),
                                        current:
                                            page.filters.author ===
                                            String(author.value),
                                    })),
                                ]}
                            />
                            {/* Later (I-1452): a searchable filter for any taxonomy, flat ones too, once the shared combobox exists. */}
                            {nesting.map((taxonomy) => (
                                <FilterButton
                                    key={taxonomy.key}
                                    name={taxonomy.key}
                                    label={taxonomy.singular}
                                    value={
                                        page.filters[taxonomy.key] === undefined
                                            ? 'All'
                                            : (treeOptions[taxonomy.key]?.find(
                                                  (option) =>
                                                      option.value ===
                                                      page.filters[
                                                          taxonomy.key
                                                      ],
                                              )?.label ?? 'All')
                                    }
                                    active={
                                        page.filters[taxonomy.key] !== undefined
                                    }
                                    open={openPanel === taxonomy.key}
                                    onOpenChange={panelOpen(taxonomy.key)}
                                    choices={[
                                        {
                                            key: 'all',
                                            label: `All ${taxonomy.plural.toLowerCase()}`,
                                            href: hrefFor({
                                                [taxonomy.key]: null,
                                            }),
                                            current:
                                                page.filters[taxonomy.key] ===
                                                undefined,
                                        },
                                        ...(
                                            treeOptions[taxonomy.key] ?? []
                                        ).map((option) => ({
                                            key: option.value,
                                            label: option.label,
                                            depth: option.depth,
                                            href: hrefFor({
                                                [taxonomy.key]: option.value,
                                            }),
                                            current:
                                                page.filters[taxonomy.key] ===
                                                option.value,
                                        })),
                                    ]}
                                />
                            ))}
                            <DateFilter
                                dates={page.dates}
                                filters={page.filters}
                                tabCount={
                                    narrowed ||
                                    page.dates.months[0]?.count === null
                                        ? null
                                        : scopeCount
                                }
                                hrefFor={hrefFor}
                                open={openPanel === 'date'}
                                onOpenChange={panelOpen('date')}
                            />
                            {narrowed && (
                                <Button
                                    asChild
                                    variant="ghost"
                                    className="h-8 shrink-0"
                                >
                                    <Link
                                        href={clearHref}
                                        data-test="clear-filters"
                                    >
                                        Clear filters
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </div>
                </div>

                {(reviewFilter !== undefined || termChips.length > 0) && (
                    <div className="flex flex-wrap gap-2">
                        {reviewFilter !== undefined && (
                            <span className="border-line bg-surface-2 inline-flex items-center gap-1 rounded-full border py-0.5 pr-0.5 pl-3 text-[13px]">
                                {reviewFilter}
                                <Link
                                    href={
                                        contentIndex(
                                            { type: page.type },
                                            {
                                                query: Object.fromEntries(
                                                    Object.entries(keep).filter(
                                                        ([key]) =>
                                                            key !== 'review',
                                                    ),
                                                ),
                                            },
                                        ).url
                                    }
                                    aria-label={`Remove the filter: ${reviewFilter}`}
                                    className="hover:bg-surface-3 inline-flex size-6 items-center justify-center rounded-full"
                                >
                                    <X className="size-3.5" />
                                </Link>
                            </span>
                        )}
                        {termChips.map((chip) => (
                            <span
                                key={chip.key}
                                data-test="filter-chip"
                                className="border-line bg-surface-2 inline-flex items-center gap-1 rounded-full border py-0.5 pr-0.5 pl-3 text-[13px]"
                            >
                                {chip.label}
                                <Link
                                    href={chip.href}
                                    aria-label={`Remove the filter: ${chip.label}`}
                                    className="hover:bg-surface-3 inline-flex size-6 items-center justify-center rounded-full"
                                >
                                    <X className="size-3.5" />
                                </Link>
                            </span>
                        ))}
                    </div>
                )}

                {page.scheduleWarning.length > 0 && (
                    <div
                        role="status"
                        className="bg-warn-soft text-warn-ink grid gap-1 rounded-md px-3 py-2 text-[13px]"
                    >
                        {page.scheduleWarning.map((line) => (
                            <p key={line} className="flex items-start gap-2">
                                <CircleAlert className="mt-0.5 size-4 shrink-0" />
                                {line}
                            </p>
                        ))}
                    </div>
                )}

                {editReport && (
                    <div
                        role="status"
                        className="border-line grid gap-2 border-y py-3 text-[13px]"
                    >
                        <p className="font-medium">
                            {editReport.done} changed;{' '}
                            {editReport.skipped.length} skipped
                            {editReport.notSent.length > 0 &&
                                `; ${editReport.notSent.length} not changed`}
                        </p>
                        {editReport.changed.length > 0 && (
                            <ul className="list-disc pl-5">
                                {editReport.changed.map((line) => (
                                    <li key={line.id}>
                                        {shownTitle(line.title)}: {line.summary}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {editReport.skipped.length > 0 && (
                            <>
                                <p className="font-medium">Skipped:</p>
                                <ul className="list-disc pl-5">
                                    {editReport.skipped.map((skip) => (
                                        <li key={skip.id}>
                                            {shownTitle(skip.title)} —{' '}
                                            {skip.reason}
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                        {editReport.notSent.length > 0 && (
                            <p className="text-danger-ink">
                                Something went wrong, so these weren’t changed:{' '}
                                {editReport.notSent
                                    .map((item) => shownTitle(item.title))
                                    .join(', ')}
                                . Select them and try again.
                            </p>
                        )}
                    </div>
                )}

                {page.bulk && page.bulk.skipped.length > 0 && (
                    <div
                        role="status"
                        className="border-line border-y py-3 text-[13px]"
                    >
                        <p className="font-medium">
                            {page.bulk.done} changed; {page.bulk.skipped.length}{' '}
                            skipped:
                        </p>
                        <ul className="list-disc pl-5">
                            {page.bulk.skipped.map((skip) => (
                                <li key={skip.id}>
                                    {shownTitle(skip.title)} — {skip.reason}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {selected.length > 0 && (
                    <BulkBar
                        count={
                            allMatching && !page.filtered
                                ? scopeCount
                                : selected.length
                        }
                        noun={{ one: singular, many: plural }}
                        selectedLabel={
                            allMatching && page.filtered
                                ? `All matching ${plural} selected`
                                : undefined
                        }
                        onClear={() => {
                            setAllMatching(false);
                            setSelected([]);
                        }}
                    >
                        {inTrash ? (
                            <>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={bulkBusy}
                                    aria-describedby="bulk-error"
                                    onClick={() =>
                                        allMatching
                                            ? setConfirming('restore')
                                            : bulk('restore')
                                    }
                                >
                                    Restore
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="destructive"
                                    disabled={bulkBusy}
                                    aria-describedby="bulk-error"
                                    onClick={() =>
                                        allMatching
                                            ? setConfirming('delete')
                                            : setDeleting(true)
                                    }
                                >
                                    Delete permanently
                                </Button>
                            </>
                        ) : (
                            <>
                                {!allMatching && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setBulkEditing(true)}
                                    >
                                        Edit…
                                    </Button>
                                )}
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="destructive"
                                    disabled={bulkBusy}
                                    aria-describedby="bulk-error"
                                    onClick={() =>
                                        allMatching
                                            ? setConfirming('trash')
                                            : bulk('trash')
                                    }
                                >
                                    Move to trash
                                </Button>
                            </>
                        )}
                        {!allMatching && (
                            <SelectAllMatching
                                page={page}
                                editableCount={editable.length}
                                selectedCount={selected.length}
                                onChoose={() => setAllMatching(true)}
                            />
                        )}
                        <InputError
                            id="bulk-error"
                            message={bulkError ?? undefined}
                        />
                    </BulkBar>
                )}

                <ConfirmItems
                    open={deleting}
                    onOpenChange={setDeleting}
                    title={
                        chosenTitles.length === 1
                            ? `Delete “${chosenTitles[0]}” permanently?`
                            : `Delete ${chosenTitles.length.toLocaleString()} ${plural} permanently?`
                    }
                    description={
                        chosenTitles.length === 1
                            ? `“${chosenTitles[0]}” and all its revisions are deleted. This can’t be undone.`
                            : 'All their revisions go with them. This can’t be undone.'
                    }
                    items={chosenTitles}
                    listLabel={`${page.labels.plural} to delete`}
                    confirmLabel="Delete permanently"
                    processing={bulkBusy}
                    onConfirm={() => bulk('delete')}
                />

                <ConfirmMatching
                    action={confirming}
                    page={page}
                    processing={bulkBusy}
                    onOpenChange={(open) => !open && setConfirming(null)}
                    onConfirm={() =>
                        confirming !== null && startMatching(confirming)
                    }
                />

                {bulkEditing && (
                    <BulkEditDialog
                        page={page}
                        rows={page.rows.filter((row) =>
                            selected.includes(row.id),
                        )}
                        onClose={() => setBulkEditing(false)}
                        onFinished={(report) => {
                            setBulkEditing(false);
                            setSelected([]);
                            setEditReport(report);
                            router.reload();
                        }}
                    />
                )}

                <DataTable
                    columns={columns}
                    rows={page.rows}
                    rowKey={(row) => row.id}
                    caption={title}
                    onSort={sort}
                    empty={
                        <EmptyState
                            kind={emptyKind}
                            title={
                                page.filtered
                                    ? `No ${title.toLowerCase()} match these filters`
                                    : activeTab === 'trash'
                                      ? 'The trash is empty'
                                      : `No ${title.toLowerCase()} here yet`
                            }
                            clearHref={
                                contentIndex(
                                    { type: page.type },
                                    { query: { tab: activeTab } },
                                ).url
                            }
                            action={
                                activeTab !== 'trash' &&
                                page.canAdd && (
                                    <Form
                                        {...ContentListController.store.form({
                                            type: page.type,
                                        })}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                variant="outline"
                                                data-test="add-new-empty"
                                                disabled={processing}
                                            >
                                                Add new {singular}
                                            </Button>
                                        )}
                                    </Form>
                                )
                            }
                        />
                    }
                />

                <Pager
                    newer={
                        page.newer
                            ? contentIndex(
                                  { type: page.type },
                                  { query: { ...keep, before: page.newer } },
                              ).url
                            : null
                    }
                    older={
                        page.older
                            ? contentIndex(
                                  { type: page.type },
                                  { query: { ...keep, after: page.older } },
                              ).url
                            : null
                    }
                />
            </div>

            <Sheet
                open={editingRow !== undefined}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                <SheetContent className="w-full overflow-y-auto sm:max-w-[480px]">
                    <SheetHeader>
                        <SheetTitle>Quick edit</SheetTitle>
                        <SheetDescription>
                            {shownTitle(editingRow?.title)}
                        </SheetDescription>
                    </SheetHeader>
                    {editingRow && (
                        <QuickEdit
                            row={editingRow}
                            page={page}
                            onClose={() => setEditing(null)}
                        />
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}
