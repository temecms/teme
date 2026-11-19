import {
    Form,
    Head,
    Link,
    router,
    setLayoutProps,
    useHttp,
    usePage,
} from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
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
import { ColumnChooser } from '@/components/list/column-chooser';
import { ConfirmItems } from '@/components/list/confirm-items';
import { DataTable } from '@/components/list/data-table';
import type { Column, SortDirection } from '@/components/list/data-table';
import { DateFilter } from '@/components/list/date-filter';
import { EmptyState } from '@/components/list/empty-state';
import {
    ChoiceFilter,
    FilterRow,
    TermSearchFilter,
} from '@/components/list/filter-row';
import { LanguageMarks } from '@/components/list/language-marks';
import { ListTreeSwitch } from '@/components/list/list-tree-switch';
import { NumberedPager } from '@/components/list/numbered-pager';
import { PluginCell } from '@/components/list/plugin-cell';
import type { RowAction } from '@/components/list/row-actions';
import { RowLine } from '@/components/list/row-line';
import { RowMenu } from '@/components/list/row-menu';
import { SearchField } from '@/components/list/search-field';
import {
    ConfirmMatching,
    SelectAllMatching,
} from '@/components/list/select-all-matching';
import type { MatchingAction } from '@/components/list/select-all-matching';
import { TitleCell } from '@/components/list/title-cell';
import { MediaModal } from '@/components/media/media-modal';
import { InPageNav } from '@/components/patterns/in-page-nav';
import { ListPage } from '@/components/patterns/list-page';
import { TermPicker } from '@/components/term-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Notice } from '@/components/ui/notice';
import { PluginMark } from '@/components/ui/plugin-mark';
import { SelectField } from '@/components/ui/select-field';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { StatusPill } from '@/components/ui/status-pill';
import { TermChips } from '@/components/ui/term-chips';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { choiceOf, DISCUSSION_CHOICES } from '@/lib/discussion';
import type { Discussion } from '@/lib/discussion';
import { __ } from '@/lib/i18n';
import { inTreeOrder } from '@/lib/term-tree';
import { shownTitle } from '@/lib/titles';
import { formatInZone } from '@/lib/zoned-time';
import {
    edit as contentEdit,
    index as contentIndex,
    preview as contentPreview,
    tree as contentTree,
} from '@/routes/content';
import { show as showMedia } from '@/routes/media';
import type {
    ContentIndexPage,
    ContentRow,
    ContentTab,
    FeaturedImage,
    MediaDetailsData,
    TermChip,
} from '@/types';
import type { CardLayout } from '@/types/list-pages';
import { useSectionCrumb } from '@/lib/crumbs';
import { formatDate } from '@/lib/dates';

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
                        <Label htmlFor={`title-${row.id}`}>{__('Title')}</Label>
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
                        <Label htmlFor={`slug-${row.id}`}>
                            {__('Address')}
                        </Label>
                        <Input
                            id={`slug-${row.id}`}
                            aria-describedby={`slug-${row.id}-error`}
                            name="slug"
                            defaultValue={row.slug}
                        />
                        {row.status === 'published' && (
                            <p className="text-ink-2 text-caption">
                                {__(
                                    'Changing a published address takes effect at once, and the old one stops working.',
                                )}
                            </p>
                        )}
                        <InputError
                            id={`slug-${row.id}-error`}
                            message={errors.slug}
                        />
                    </div>
                    {page.can.changeAuthor && (
                        <div className="grid gap-2">
                            <Label htmlFor={`author-${row.id}`}>
                                {__('Author')}
                            </Label>
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
                            <Label htmlFor={`date-${row.id}`}>
                                {__('Published')}
                            </Label>
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
                                {__('Discussion')}
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
                                className="text-ink-2 text-caption"
                            >
                                {choiceOf(discussion).meaning}
                                {!row.discussion.canSet &&
                                    ` ${__('Whoever publishes it sets this.')}`}
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
                            canCreate={taxonomy.canCreate}
                            segment={taxonomy.segment}
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
                        <span className="text-small font-medium">
                            {__('Featured image')}
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
                                    className="size-16 rounded-md object-cover"
                                />
                            ) : (
                                <span className="text-ink-2 text-small">
                                    {__('None')}
                                </span>
                            )}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                aria-describedby={`image-${row.id}-error`}
                                onClick={() => setPicking(true)}
                            >
                                {image ? __('Change…') : __('Choose…')}
                            </Button>
                            {image && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setImage(null)}
                                >
                                    {__('Remove')}
                                </Button>
                            )}
                        </div>
                        {image && (
                            <div className="grid gap-1">
                                <Label htmlFor={`alt-${row.id}`}>
                                    {__('Alt text for this :singular', {
                                        singular:
                                            page.labels.singular.toLowerCase(),
                                    })}
                                </Label>
                                <Input
                                    id={`alt-${row.id}`}
                                    name="featured_image_alt"
                                    defaultValue={image.alt ?? ''}
                                    placeholder={__(
                                        "Leave empty to use the image's own",
                                    )}
                                />
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
                            action={__('Set featured image')}
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
                            {__('Save')}
                        </Button>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            {__('Cancel')}
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

/** The tabs a list shows, in v10's order: Mine and Private are reached by their address, and show only while open. */
const LIST_TABS = [
    'all',
    'published',
    'drafts',
    'scheduled',
    'review',
    'trash',
];

/** The list's tabs in LIST_TABS' order, with the open one added at the end when it is another. */
function listTabs(tabs: ContentTab[], current: string): ContentTab[] {
    const shown = LIST_TABS.map((key) =>
        tabs.find((tab) => tab.key === key),
    ).filter((tab) => tab !== undefined);
    const open = tabs.find(
        (tab) => tab.key === current && !LIST_TABS.includes(tab.key),
    );

    return open === undefined ? shown : [...shown, open];
}

/** The line under a list's name: Posts' and Pages' own, by the core type's key, and a plain one for a type made here. */
const DESCRIPTIONS: Record<string, () => string> = {
    post: () => __('Articles for the site.'),
    page: () =>
        __(
            'The site’s fixed pages: About, Contact, the policies. They sit outside the news feed.',
        ),
};

const describe = (typeKey: string): string =>
    DESCRIPTIONS[typeKey]?.() ?? __('Everything of this kind on the site.');

/** A row's title for a sentence: "Untitled" when it has none. */
const titleOf = (row: ContentRow): string => row.title.trim() || __('Untitled');

/**
 * A row's state as a word on its pill, in the Status column
 * (docs/proposals/content-lists.md): in the
 * trash, a schedule that cannot run, a review, or its status, and Private
 * for a live item only some may read.
 */
function StatusCell({ row, inTrash }: { row: ContentRow; inTrash: boolean }) {
    // Later (I-1451): Sticky, Home page and Posts page, as WordPress lists them.
    if (inTrash || row.trashed) {
        return <StatusPill tone="neutral">{__('In Trash')}</StatusPill>;
    }

    if (row.status === 'scheduled' && row.scheduleIssue !== null) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <StatusPill tone="warn" tabIndex={0}>
                        {__('Schedule held')}
                    </StatusPill>
                </TooltipTrigger>
                <TooltipContent>{scheduleReason(row)}</TooltipContent>
            </Tooltip>
        );
    }

    if (row.status === 'published') {
        // A live item's update waiting for review says so first; then who may read it (I-1700).
        if (row.review !== null) {
            return (
                <StatusPill tone="warn">{__('Update in review')}</StatusPill>
            );
        }

        if (row.visibility === 'private') {
            return <StatusPill tone="neutral">{__('Private')}</StatusPill>;
        }

        if (row.visibility === 'protected') {
            return (
                <StatusPill tone="neutral">
                    {__('Password protected')}
                </StatusPill>
            );
        }

        return <StatusPill tone="ok">{__('Published')}</StatusPill>;
    }

    if (row.status === 'scheduled') {
        return <StatusPill tone="info">{__('Scheduled')}</StatusPill>;
    }

    // Amber, with its triangle: someone has to act on it.
    if (row.status === 'review') {
        return <StatusPill tone="warn">{__('In review')}</StatusPill>;
    }

    return <StatusPill tone="neutral">{__('Draft')}</StatusPill>;
}

/** Why a scheduled item cannot publish as things stand, in today's words. */
const scheduleReason = (row: ContentRow): string =>
    row.scheduleIssue === 'failed' ? __('Failed') : __('Held');

/**
 * What goes on a row's line (I-3398): Edit, Quick edit, and View for a live
 * item or Preview for anything else; in the trash, Restore. Each in a new
 * tab when it leaves the admin.
 */
function lineActions(
    row: ContentRow,
    page: ContentIndexPage,
    quickEdit: () => void,
): RowAction[] {
    if (row.trashed) {
        return [
            {
                id: 'restore',
                owner: 'core',
                label: __('Restore'),
                form: ContentActionController.action.form({
                    type: page.type,
                    content: row.id,
                    action: 'restore',
                }),
            },
        ];
    }

    const look: RowAction =
        row.viewUrl !== null
            ? {
                  id: 'view',
                  owner: 'core',
                  label: __('View'),
                  href: row.viewUrl,
                  newTab: true,
              }
            : {
                  id: 'preview',
                  owner: 'core',
                  label: __('Preview'),
                  href: contentPreview(row.id).url,
                  newTab: true,
              };

    // Other people's unpublished work is listed, and only looked at (I-409).
    if (!row.canEdit) {
        return [look];
    }

    return [
        {
            id: 'edit',
            owner: 'core',
            label: __('Edit'),
            href: contentEdit(row.id).url,
        },
        {
            id: 'quick-edit',
            owner: 'core',
            label: __('Quick edit'),
            onSelect: quickEdit,
        },
        look,
    ];
}

/**
 * What goes in a row's "…" menu (I-3398): at most one state action, then
 * plugins' actions (none yet: I-200), then Trash; in the trash, Delete
 * permanently, which asks first. A row the person may not edit has none.
 */
function menuActions(row: ContentRow, page: ContentIndexPage): RowAction[] {
    if (!row.canEdit) {
        return [];
    }

    const action = (
        name: 'trash' | 'publish' | 'unpublish',
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
            {
                id: 'delete',
                owner: 'core',
                label: __('Delete permanently'),
                destructive: true,
                form: ContentActionController.destroy.form({
                    type: page.type,
                    content: row.id,
                }),
                wrap: (button) => (
                    <ConfirmAction
                        title={__('Delete “:title” permanently?', {
                            title: titleOf(row),
                        })}
                        description={__(
                            '“:title” and all its revisions are deleted. This can’t be undone.',
                            { title: titleOf(row) },
                        )}
                        confirmLabel={__('Delete permanently')}
                    >
                        {button}
                    </ConfirmAction>
                ),
            },
        ];
    }

    // Publishing around a pending review would skip the review the writer
    // asked for: whoever may decide is offered Approve in its place, and
    // anyone else neither.
    const state: RowAction[] = row.review?.canDecide
        ? [
              {
                  id: 'approve',
                  owner: 'core',
                  label: __('Approve'),
                  form: ReviewController.approve.form(row.id),
                  fields: { review_id: row.review.id },
              },
          ]
        : page.can.publish && row.status !== 'published' && row.review === null
          ? [action('publish', __('Publish'))]
          : page.can.publish && row.status === 'published'
            ? [action('unpublish', __('Revert to draft'))]
            : [];

    return [...state, action('trash', __('Trash'), true)];
}

/**
 * When the row happened, or will, and a second line saying which date it
 * is. A scheduled item says when it publishes, on the site's clock with the
 * zone named, and why its schedule cannot run when it cannot; an item in
 * review says who submitted it and when.
 */
function DateCell({ row, page }: { row: ContentRow; page: ContentIndexPage }) {
    if (row.status === 'scheduled' && row.scheduledFor !== null) {
        return (
            <span className="grid">
                <span>
                    {formatInZone(row.scheduledFor, page.siteTimezone)} (
                    {page.siteTimezone})
                </span>
                <span
                    className={
                        row.scheduleIssue === 'failed'
                            ? 'text-small text-danger-ink'
                            : row.scheduleIssue === 'held'
                              ? 'text-small text-warn-ink'
                              : 'text-small text-ink-3'
                    }
                >
                    {row.scheduleIssue === null
                        ? __('Scheduled')
                        : scheduleReason(row)}
                </span>
            </span>
        );
    }

    if (row.review !== null) {
        return (
            <span className="grid">
                <span>
                    {__('Submitted :date', {
                        date: formatInZone(
                            row.review.submittedAt,
                            page.siteTimezone,
                        ),
                    })}
                </span>
                <span className="text-small text-ink-3">
                    {__('by :name', {
                        name: row.review.submittedBy ?? __('a removed account'),
                    })}
                </span>
            </span>
        );
    }

    // Live is published and not in the trash: a reverted draft keeps its
    // published date, and an item in the trash keeps its status.
    const live = row.status === 'published' && !row.trashed;

    return (
        <span className="grid">
            <span>
                {formatDate(
                    live ? (row.publishedAt ?? row.updatedAt) : row.updatedAt,
                )}
            </span>
            <span className="text-small text-ink-3">
                {live ? __('Published') : __('Last edited')}
            </span>
        </span>
    );
}

/** Each optional column's declared width (docs/architecture/design-system.md, section 4.26). */
const WIDTHS: Record<string, number> = {
    author: 128,
    comments: 116,
    date: 116,
};

export default function ContentIndex(page: ContentIndexPage) {
    const { preferences, contentTypes } = usePage().props;
    // The type's key names its columns preference; the URL carries its segment.
    const typeKey =
        contentTypes.find((type) => type.segment === page.type)?.key ??
        page.type;
    const shownColumns = preferences[`lists.${typeKey}.columns`];
    const perPage = preferences['lists.per_page'];
    const [selected, setSelected] = useState<string[]>([]);
    // The row ticked last, for Shift+click to tick the rows between.
    const lastTicked = useRef<string | null>(null);
    const shiftHeld = useRef(false);
    // Every match of the list, not only the page's rows (I-143).
    const [allMatching, setAllMatching] = useState(false);
    const [confirming, setConfirming] = useState<MatchingAction | null>(null);
    // Titles by default; the text is the published revision's (SearchText).
    const [searchIn, setSearchIn] = useState(
        page.filters.in === 'body' ? 'body' : 'title',
    );
    const [editing, setEditing] = useState<string | null>(null);
    // What opened Quick edit, to take the focus back to when it closes.
    const opener = useRef<HTMLElement | null>(null);
    const [bulkEditing, setBulkEditing] = useState(false);
    // Bulk Edit sets no flash: its report is the dialog's own, added up
    // from its batches.
    const [editReport, setEditReport] = useState<BulkEditReport | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [bulkBusy, setBulkBusy] = useState(false);
    const [bulkError, setBulkError] = useState<string | null>(null);
    const [dateOpen, setDateOpen] = useState(false);
    const searchRef = useRef<HTMLInputElement>(null);

    // Any new rows (a visit, a reload) go back to the page's own selection.
    useEffect(() => setAllMatching(false), [page.rows]);

    // One of this person's jobs ended, perhaps one moving these rows: catch up (I-143).
    useEffect(() => {
        const reload = () => router.reload();

        window.addEventListener('teme:jobs-ended', reload);

        return () => window.removeEventListener('teme:jobs-ended', reload);
    }, []);
    const title = page.labels.plural;
    const contentCrumb = useSectionCrumb('content');

    setLayoutProps({
        breadcrumbs: [
            contentCrumb,
            { title, href: contentIndex({ type: page.type }) },
        ],
    });
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
    const authorSortable =
        activeTab !== 'mine' && page.filters.author === undefined;
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
    ) as Record<string, string>;
    // Each nesting taxonomy's filter lists its tree; a flat one, such as
    // tags, can number in thousands, so its filter asks the server (I-1452).
    const nesting = useMemo(
        () => page.typeTaxonomies.filter((taxonomy) => taxonomy.hierarchical),
        [page.typeTaxonomies],
    );
    const flat = useMemo(
        () => page.typeTaxonomies.filter((taxonomy) => !taxonomy.hierarchical),
        [page.typeTaxonomies],
    );
    const treeOptions = useMemo(
        () =>
            Object.fromEntries(
                nesting.map((taxonomy) => {
                    const terms = page.termTrees[taxonomy.key] ?? [];
                    const names = new Map(
                        terms.map((term) => [term.id, term.name]),
                    );
                    const parents = new Map(
                        terms.map((term) => [term.id, term.parentId]),
                    );
                    // The names above a term, the top first.
                    const pathOf = (id: string): string[] => {
                        const path: string[] = [];
                        let at = parents.get(id) ?? null;

                        while (at !== null && path.length < 32) {
                            path.unshift(names.get(at) ?? '');
                            at = parents.get(at) ?? null;
                        }

                        return path;
                    };

                    return [
                        taxonomy.key,
                        inTreeOrder(terms).map(({ term }) => {
                            const above = pathOf(term.id);

                            return {
                                value: term.id,
                                label: term.name,
                                description:
                                    above.length > 0
                                        ? [...above, term.name].join(' › ')
                                        : undefined,
                                keywords: above,
                            };
                        }),
                    ];
                }),
            ),
        [nesting, page.termTrees],
    );
    // Every filter is a visit to the list with the others kept: the page
    // dropped, order kept, and the tab always written (I-3129).
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
    const filterTo = (patch: Record<string, string | null>) =>
        router.visit(hrefFor(patch), { preserveScroll: true });
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
    // How many filters are on, for Filters (n) on a phone: the search is beside them.
    const activeFilters =
        ['author', ...page.typeTaxonomies.map((taxonomy) => taxonomy.key)]
            .map((key) => page.filters[key])
            .filter((value) => value !== undefined).length +
        (['month', 'from', 'to'].some((key) => page.filters[key] !== undefined)
            ? 1
            : 0);
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
            ? __('Has a pending review')
            : page.filters.review === 'submitted'
              ? __('Submitted by you')
              : undefined;
    const emptyKind = page.filtered ? 'nothing-matches' : 'nothing-yet';

    // Other people's unpublished work is listed by title and never acted on
    // (I-409): only the rows this person may edit can be selected.
    const editable = page.rows.filter((row) => row.canEdit);
    const tick = (row: ContentRow, checked: boolean) => {
        setAllMatching(false);
        const from = page.rows.findIndex(
            (each) => each.id === lastTicked.current,
        );
        const to = page.rows.findIndex((each) => each.id === row.id);
        // Shift+click ticks or unticks every row the person may edit between this one and the last.
        const range =
            shiftHeld.current && from !== -1
                ? page.rows
                      .slice(Math.min(from, to), Math.max(from, to) + 1)
                      .filter((each) => each.canEdit)
                      .map((each) => each.id)
                : [row.id];

        setSelected((current) =>
            checked
                ? [...new Set([...current, ...range])]
                : current.filter((id) => !range.includes(id)),
        );
        lastTicked.current = row.id;
        shiftHeld.current = false;
    };
    const thumbnails =
        Array.isArray(shownColumns) && shownColumns.includes('featured');

    const select: Column<ContentRow> = {
        id: 'select',
        owner: 'core',
        width: 48,
        className: 'pe-0',
        header: (
            <Checkbox
                aria-label={__('Select all on this page')}
                disabled={editable.length === 0}
                checked={
                    editable.length > 0 &&
                    editable.every((row) => selected.includes(row.id))
                }
                onCheckedChange={(checked) => {
                    setAllMatching(false);
                    setSelected(checked ? editable.map((row) => row.id) : []);
                }}
            />
        ),
        cell: (row) =>
            row.canEdit && (
                <Checkbox
                    aria-label={__('Select :title', {
                        title: shownTitle(row.title),
                    })}
                    checked={selected.includes(row.id)}
                    onClick={(event) => {
                        shiftHeld.current = event.shiftKey;
                    }}
                    onCheckedChange={(checked) => tick(row, checked === true)}
                />
            ),
    };
    const siteLanguages = page.languages ?? [];
    const titleColumn: Column<ContentRow> = {
        id: 'title',
        owner: 'core',
        header: __('Title'),
        sort: sortBy('title'),
        minWidth: 320,
        className: 'whitespace-normal max-sm:min-w-60',
        // A trashed item has no editor (it answers 404), and someone else's
        // the person may not edit answers 403, so either title stays plain.
        cell: (row) => (
            <TitleCell
                title={shownTitle(row.title)}
                href={
                    row.trashed || !row.canEdit ? null : contentEdit(row.id).url
                }
                thumbnail={
                    thumbnails
                        ? (row.featuredImage?.thumbUrl ?? null)
                        : undefined
                }
                line={
                    <>
                        {siteLanguages.length > 0 && (
                            <LanguageMarks
                                languages={siteLanguages}
                                states={row.versions ?? {}}
                            />
                        )}
                        <RowLine
                            title={shownTitle(row.title)}
                            items={lineActions(row, page, () => {
                                opener.current =
                                    document.activeElement as HTMLElement | null;
                                setEditing(row.id);
                            })}
                        />
                    </>
                }
            />
        ),
    };
    const status: Column<ContentRow> = {
        id: 'status',
        owner: 'core',
        header: __('Status'),
        width: 120,
        cell: (row) => <StatusCell row={row} inTrash={inTrash} />,
    };
    const optional: (Column<ContentRow> & { label: string })[] = [
        ...(page.hasParent
            ? [
                  {
                      id: 'parent',
                      owner: 'core',
                      header: __('Parent'),
                      label: __('Parent'),
                      width: 146,
                      cell: (row: ContentRow) =>
                          row.parent ? (
                              <span className="block truncate">
                                  {shownTitle(row.parent.title)}
                              </span>
                          ) : (
                              <span className="text-ink-3">
                                  <span aria-hidden="true">—</span>
                                  <span className="sr-only">
                                      {__('No parent')}
                                  </span>
                              </span>
                          ),
                  },
              ]
            : []),
        {
            id: 'author',
            owner: 'core',
            header: __('Author'),
            label: __('Author'),
            width: WIDTHS.author,
            sort: authorSortable ? sortBy('author') : undefined,
            cell: (row) => (
                <span className="block truncate">{row.authorName ?? '—'}</span>
            ),
        },
        ...page.typeTaxonomies.map(
            (taxonomy): Column<ContentRow> & { label: string } => ({
                id: `taxonomy-${taxonomy.key}`,
                owner: 'core',
                header: taxonomy.plural,
                label: taxonomy.plural,
                width: taxonomy.hierarchical ? 146 : 168,
                cell: (row) => (
                    <TermChips
                        terms={row.terms[taxonomy.key] ?? []}
                        noun={{
                            one: taxonomy.singular.toLowerCase(),
                            many: taxonomy.plural.toLowerCase(),
                        }}
                        emptyLabel={__('No :plural', {
                            plural: taxonomy.plural.toLowerCase(),
                        })}
                    />
                ),
            }),
        ),
        ...page.listFields.map(
            (field): Column<ContentRow> & { label: string } => ({
                id: `field-${field.key}`,
                owner: 'core',
                header: field.label,
                label: field.label,
                width: field.kind === 'number' ? 110 : 146,
                className:
                    field.kind === 'number'
                        ? 'text-end tabular-nums'
                        : undefined,
                cell: (row) => {
                    const value = row.fields?.[field.key];

                    return value === undefined ? (
                        <span className="text-ink-3">—</span>
                    ) : (
                        <span className="block truncate">
                            {field.kind === 'datetime'
                                ? formatDate(value)
                                : value}
                        </span>
                    );
                },
            }),
        ),
        ...(page.hasTemplate
            ? [
                  {
                      id: 'template',
                      owner: 'core',
                      header: __('Template'),
                      label: __('Template'),
                      width: 116,
                      cell: (row: ContentRow) => (
                          <span className="block truncate">
                              {row.template ?? __('Default')}
                          </span>
                      ),
                  },
              ]
            : []),
        ...(page.takesComments
            ? [
                  {
                      id: 'comments',
                      owner: 'core',
                      header: __('Comments'),
                      label: __('Comments'),
                      width: WIDTHS.comments,
                      className: 'text-end tabular-nums',
                      cell: (row: ContentRow) => (
                          <CommentsCell itemId={row.id} counts={row.comments} />
                      ),
                  },
              ]
            : []),
        ...(siteLanguages.length > 0
            ? [
                  {
                      id: 'languages',
                      owner: 'core',
                      header: __('Languages'),
                      label: __('Languages'),
                      width: 168,
                      cell: (row: ContentRow) => (
                          <LanguageMarks
                              languages={siteLanguages}
                              states={row.versions ?? {}}
                              className="flex-nowrap"
                          />
                      ),
                  },
              ]
            : []),
        {
            id: 'date',
            owner: 'core',
            header: __('Date'),
            label: __('Date'),
            width: WIDTHS.date,
            sort: sortBy(dateSort),
            cell: (row) => <DateCell row={row} page={page} />,
        },
        ...page.pluginColumns.map(
            (
                column,
            ): Column<ContentRow> & { label: string; plugin: string } => ({
                id: column.key,
                owner: column.plugin,
                header: (
                    <span className="inline-flex items-center gap-1 whitespace-nowrap">
                        {column.label}
                        <PluginMark name={column.plugin} iconOnly />
                    </span>
                ),
                label: column.label,
                plugin: column.plugin,
                width: column.width,
                className:
                    column.kind === 'number'
                        ? 'text-end tabular-nums'
                        : undefined,
                cell: (row) => (
                    <PluginCell
                        value={page.pluginCells[column.key]?.[row.id]}
                    />
                ),
            }),
        ),
    ];
    const menu: Column<ContentRow> = {
        id: 'menu',
        owner: 'core',
        width: 56,
        className: 'px-2',
        header: <span className="sr-only">{__('Actions')}</span>,
        cell: (row) => {
            const items = menuActions(row, page);

            return (
                items.length > 0 && (
                    <RowMenu title={shownTitle(row.title)} items={items} />
                )
            );
        },
    };
    // The person's order, then any column the preference does not name, in
    // the table's own. `fields` stands for every one of the type's own fields
    // until the person chooses among them.
    const named = Array.isArray(shownColumns) ? shownColumns : null;
    const fieldIds = page.listFields.map((field) => `field-${field.key}`);
    const namedIds = (named ?? []).flatMap((id) =>
        id === 'fields'
            ? fieldIds.filter((fieldId) => !(named ?? []).includes(fieldId))
            : [id],
    );
    const ordered = [
        ...namedIds
            .map((id) => optional.find((column) => column.id === id))
            .filter((column) => column !== undefined),
        ...optional.filter((column) => !namedIds.includes(column.id)),
    ];
    // Without a preference, the columns that fit beside the title at 1440
    // wide: a flat taxonomy, Comments and Languages are offered, not shown;
    // the language marks under the title already say what Languages would.
    const offeredOnly = new Set([
        'comments',
        'languages',
        ...flat.map((taxonomy) => `taxonomy-${taxonomy.key}`),
    ]);
    const isShown = (id: string) =>
        named === null
            ? !offeredOnly.has(id)
            : named.includes(id) ||
              (id.startsWith('field-') && named.includes('fields'));
    // Status is always shown, just before the Date, as v10 draws the list;
    // at the end of the shown columns when the Date is hidden.
    const shownOptional = ordered.filter((column) => isShown(column.id));
    const dateAt = shownOptional.findIndex((column) => column.id === 'date');
    const statusAt = dateAt === -1 ? shownOptional.length : dateAt;
    const columns: Column<ContentRow>[] = [
        select,
        titleColumn,
        ...shownOptional.slice(0, statusAt),
        status,
        ...shownOptional.slice(statusAt),
        menu,
    ];
    const pluginFactIds = ordered
        .filter(
            (column) => isShown(column.id) && column.id.startsWith('plugin:'),
        )
        .map((column) => column.id)
        .slice(0, 2);
    const cards: CardLayout = {
        titleColumn: 'title',
        select: 'select',
        menu: 'menu',
        facts: ['status', 'date', 'author', ...pluginFactIds],
        selectLabel: __('Select all :count :plural shown', {
            count: editable.length,
            plural,
        }),
        sort: sortable
            ? {
                  options: [
                      { key: 'updated', label: __('Last modified') },
                      ...(dateSort !== 'updated'
                          ? [{ key: dateSort, label: __('Date') }]
                          : []),
                      { key: 'title', label: __('Title') },
                      ...(authorSortable
                          ? [{ key: 'author', label: __('Author') }]
                          : []),
                  ],
                  current: page.sort,
                  direction: page.direction,
              }
            : undefined,
    };
    const selectedKeys = new Set<string | number>(selected);

    return (
        <ListPage
            title={title}
            description={describe(typeKey)}
            actions={
                page.canAdd && (
                    <Form
                        {...ContentListController.store.form({
                            type: page.type,
                        })}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="primary"
                                data-test="add-new"
                                disabled={processing}
                            >
                                <Plus aria-hidden="true" />
                                {__('New :singular', { singular })}
                            </Button>
                        )}
                    </Form>
                )
            }
        >
            <Head title={title} />

            {/* Its own grid cell may be narrower than its views, which then scroll sideways inside it (I-3422). */}
            <div className="min-w-0">
                <InPageNav
                    label={__('Status')}
                    visible={LIST_TABS.length}
                    items={listTabs(page.tabs, activeTab).map((tab) => ({
                        label: tab.label,
                        count: tab.count,
                        current: tab.key === activeTab,
                        href: contentIndex(
                            { type: page.type },
                            { query: { tab: tab.key } },
                        ).url,
                    }))}
                />
            </div>

            {page.scheduleWarning.length > 0 && (
                <Notice tone="warn" role="status">
                    {page.scheduleWarning.map((line) => (
                        <p key={line}>{line}</p>
                    ))}
                </Notice>
            )}

            <FilterRow
                active={activeFilters}
                search={
                    <form
                        role="search"
                        onSubmit={(event) => {
                            event.preventDefault();
                            searchTo(searchIn);
                        }}
                        className="flex w-full items-center gap-2 sm:w-auto"
                    >
                        <SearchField
                            ref={searchRef}
                            data-test="list-search"
                            name="search"
                            defaultValue={page.filters.search ?? ''}
                            placeholder={
                                searchIn === 'body'
                                    ? __('Search text')
                                    : __('Search titles')
                            }
                            aria-label={
                                searchIn === 'body'
                                    ? __('Search text')
                                    : __('Search titles')
                            }
                            className="min-w-0 flex-1 sm:w-70 sm:flex-none"
                        />
                        <SelectField
                            name="in"
                            value={searchIn}
                            onValueChange={(next) => {
                                setSearchIn(next);

                                // Typed words search at once in the place chosen; else it goes with the next search.
                                if ((searchRef.current?.value ?? '') !== '') {
                                    searchTo(next);
                                }
                            }}
                            aria-label={__('Search in')}
                            aria-describedby="search-in-hint"
                            options={[
                                { value: 'title', label: __('In titles') },
                                { value: 'body', label: __('In text') },
                            ]}
                            className="w-32 shrink-0"
                        />
                        {searchIn === 'body' && (
                            <span
                                id="search-in-hint"
                                className="text-ink-3 text-small"
                            >
                                {__('Published text only')}
                            </span>
                        )}
                    </form>
                }
                filters={
                    <>
                        <ChoiceFilter
                            testId="filter-author"
                            label={__('Author')}
                            allLabel={__('All authors')}
                            options={page.authors.map((author) => ({
                                value: String(author.value),
                                label: author.label,
                            }))}
                            value={page.filters.author}
                            onChange={(value) => filterTo({ author: value })}
                        />
                        {nesting.map((taxonomy) => (
                            <ChoiceFilter
                                key={taxonomy.key}
                                testId={`filter-${taxonomy.key}`}
                                label={taxonomy.singular}
                                allLabel={__('All :plural', {
                                    plural: taxonomy.plural.toLowerCase(),
                                })}
                                options={treeOptions[taxonomy.key] ?? []}
                                value={page.filters[taxonomy.key]}
                                onChange={(value) =>
                                    filterTo({ [taxonomy.key]: value })
                                }
                            />
                        ))}
                        {flat.map((taxonomy) => (
                            <TermSearchFilter
                                key={taxonomy.key}
                                testId={`filter-${taxonomy.key}`}
                                taxonomy={taxonomy.key}
                                label={taxonomy.singular}
                                allLabel={__('All :plural', {
                                    plural: taxonomy.plural.toLowerCase(),
                                })}
                                value={page.filters[taxonomy.key]}
                                valueName={page.chosenTerms[taxonomy.key]}
                                onChange={(value) =>
                                    filterTo({ [taxonomy.key]: value })
                                }
                            />
                        ))}
                        {siteLanguages.length > 0 && (
                            <ChoiceFilter
                                testId="filter-language"
                                label={__('Language')}
                                allLabel={__('All languages')}
                                options={[
                                    ...siteLanguages.map((language) => ({
                                        value: language.tag,
                                        label: language.name,
                                    })),
                                    ...siteLanguages.map((language) => ({
                                        value: `missing:${language.tag}`,
                                        label: __(
                                            'Missing a version in :language',
                                            {
                                                language: language.name,
                                            },
                                        ),
                                    })),
                                ]}
                                value={page.filters.language}
                                onChange={(value) =>
                                    filterTo({ language: value })
                                }
                            />
                        )}
                        <DateFilter
                            dates={page.dates}
                            filters={page.filters}
                            tabCount={
                                narrowed || page.dates.months[0]?.count === null
                                    ? null
                                    : scopeCount
                            }
                            hrefFor={hrefFor}
                            open={dateOpen}
                            onOpenChange={setDateOpen}
                        />
                    </>
                }
                clear={
                    <Button asChild variant="ghost" className="shrink-0">
                        <Link href={clearHref} data-test="clear-filters">
                            {__('Clear filters')}
                        </Link>
                    </Button>
                }
                end={
                    <>
                        {page.hasParent && (
                            <ListTreeSwitch
                                listHref={contentIndex({ type: page.type }).url}
                                treeHref={contentTree({ type: page.type }).url}
                                current="list"
                            />
                        )}
                        <ColumnChooser
                            list={typeKey}
                            columns={ordered.map((column) => ({
                                id: column.id,
                                label: column.label,
                                width: column.width,
                                plugin:
                                    'plugin' in column &&
                                    typeof column.plugin === 'string'
                                        ? column.plugin
                                        : undefined,
                            }))}
                            shown={ordered
                                .filter((column) => isShown(column.id))
                                .map((column) => column.id)}
                            thumbnails={thumbnails}
                            perPage={perPage}
                        />
                    </>
                }
            />

            {page.pluginColumnProblems.length > 0 && (
                <p role="status" className="text-small text-ink-2">
                    {__(
                        'Some plugin columns could not be shown this time: :plugins. The rest of the list is unaffected.',
                        { plugins: page.pluginColumnProblems.join(', ') },
                    )}
                </p>
            )}

            {reviewFilter !== undefined && (
                <div className="flex flex-wrap items-center gap-1">
                    <StatusPill tone="neutral" flat>
                        {reviewFilter}
                    </StatusPill>
                    {/* Its own 24-pixel button: the pill's built-in remove is smaller than a target may be (I-3398). */}
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-xs"
                        aria-label={__('Remove the filter: :filter', {
                            filter: reviewFilter,
                        })}
                        onClick={() => filterTo({ review: null })}
                    >
                        <X aria-hidden="true" />
                    </Button>
                </div>
            )}

            {editReport && (
                <Notice
                    tone={editReport.notSent.length > 0 ? 'warn' : 'info'}
                    role="status"
                    title={__(':done changed; :skipped skipped', {
                        done: editReport.done,
                        skipped: editReport.skipped.length,
                    })}
                >
                    {editReport.notSent.length > 0 && (
                        <p>
                            {__(':count not changed', {
                                count: editReport.notSent.length,
                            })}
                        </p>
                    )}
                    {editReport.changed.length > 0 && (
                        <ul className="list-disc ps-5">
                            {editReport.changed.map((line) => (
                                <li key={line.id}>
                                    {shownTitle(line.title)}: {line.summary}
                                </li>
                            ))}
                        </ul>
                    )}
                    {editReport.skipped.length > 0 && (
                        <>
                            <p className="font-medium">{__('Skipped:')}</p>
                            <ul className="list-disc ps-5">
                                {editReport.skipped.map((skip) => (
                                    <li key={skip.id}>
                                        {shownTitle(skip.title)} — {skip.reason}
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                    {editReport.notSent.length > 0 && (
                        <p className="text-danger-ink">
                            {__(
                                'Something went wrong, so these weren’t changed: :titles. Select them and try again.',
                                {
                                    titles: editReport.notSent
                                        .map((item) => shownTitle(item.title))
                                        .join(', '),
                                },
                            )}
                        </p>
                    )}
                </Notice>
            )}

            {page.bulk && page.bulk.skipped.length > 0 && (
                <Notice
                    tone="info"
                    role="status"
                    title={__(':done changed; :skipped skipped:', {
                        done: page.bulk.done,
                        skipped: page.bulk.skipped.length,
                    })}
                >
                    <ul className="list-disc ps-5">
                        {page.bulk.skipped.map((skip) => (
                            <li key={skip.id}>
                                {shownTitle(skip.title)} — {skip.reason}
                            </li>
                        ))}
                    </ul>
                </Notice>
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
                            ? __('All matching :plural selected', { plural })
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
                                variant="neutral"
                                className="bg-surface"
                                disabled={bulkBusy}
                                aria-describedby="bulk-error"
                                onClick={() =>
                                    allMatching
                                        ? setConfirming('restore')
                                        : bulk('restore')
                                }
                            >
                                {__('Restore')}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="danger"
                                disabled={bulkBusy}
                                aria-describedby="bulk-error"
                                onClick={() =>
                                    allMatching
                                        ? setConfirming('delete')
                                        : setDeleting(true)
                                }
                            >
                                {__('Delete permanently')}
                            </Button>
                        </>
                    ) : (
                        <>
                            {!allMatching && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="neutral"
                                    className="bg-surface"
                                    onClick={() => setBulkEditing(true)}
                                >
                                    {__('Edit…')}
                                </Button>
                            )}
                            <Button
                                type="button"
                                size="sm"
                                variant="neutral"
                                className="bg-surface text-danger-ink"
                                disabled={bulkBusy}
                                aria-describedby="bulk-error"
                                onClick={() =>
                                    allMatching
                                        ? setConfirming('trash')
                                        : bulk('trash')
                                }
                            >
                                {__('Move to trash')}
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
                        ? __('Delete “:title” permanently?', {
                              title: chosenTitles[0],
                          })
                        : __('Delete :count :plural permanently?', {
                              count: chosenTitles.length.toLocaleString(),
                              plural,
                          })
                }
                description={
                    chosenTitles.length === 1
                        ? __(
                              '“:title” and all its revisions are deleted. This can’t be undone.',
                              { title: chosenTitles[0] },
                          )
                        : __(
                              'All their revisions go with them. This can’t be undone.',
                          )
                }
                items={chosenTitles}
                listLabel={__(':plural to delete', {
                    plural: page.labels.plural,
                })}
                confirmLabel={__('Delete permanently')}
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
                    rows={page.rows.filter((row) => selected.includes(row.id))}
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
                selectedKeys={selectedKeys}
                pinned={{ start: ['select', 'title'], end: ['menu'] }}
                cards={cards}
                wideNote={__('More columns: scroll sideways')}
                footer={
                    <NumberedPager
                        pages={page.paging}
                        perPage={perPage}
                        href={(query) =>
                            contentIndex(
                                { type: page.type },
                                {
                                    query: {
                                        ...keep,
                                        tab: activeTab,
                                        ...query,
                                    },
                                },
                            ).url
                        }
                    />
                }
                empty={
                    <EmptyState
                        kind={emptyKind}
                        title={
                            page.filtered
                                ? __('No :plural match these filters', {
                                      plural: title.toLowerCase(),
                                  })
                                : activeTab === 'trash'
                                  ? __('The trash is empty')
                                  : __('No :plural here yet', {
                                        plural: title.toLowerCase(),
                                    })
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
                                            variant="neutral"
                                            data-test="add-new-empty"
                                            disabled={processing}
                                        >
                                            {__('New :singular', {
                                                singular,
                                            })}
                                        </Button>
                                    )}
                                </Form>
                            )
                        }
                    />
                }
            />

            <Sheet
                open={editingRow !== undefined}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                <SheetContent
                    className="w-full sm:max-w-120"
                    onCloseAutoFocus={(event) => {
                        if (opener.current?.isConnected) {
                            event.preventDefault();
                            opener.current.focus();
                        }
                    }}
                >
                    <SheetHeader>
                        <SheetTitle>{__('Quick edit')}</SheetTitle>
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
        </ListPage>
    );
}
