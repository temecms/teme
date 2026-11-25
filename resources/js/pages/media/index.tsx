import {
    Head,
    Link,
    router,
    setLayoutProps,
    useHttp,
    usePoll,
} from '@inertiajs/react';
import { Grid2x2, List, Trash2, TriangleAlert, Upload } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { showToast } from '@/components/flash-toast';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { mediaKind } from '@/components/media/attachment-fields';
import { MediaFilters } from '@/components/media/media-filters';
import type { MediaFilterValues } from '@/components/media/media-filters';
import {
    MediaGrid,
    Thumb,
    TILE_SIZES,
    tileOf,
} from '@/components/media/media-grid';
import { PageDrop } from '@/components/media/page-drop';
import { UploadList } from '@/components/media/upload-list';
import {
    uploadedSentence,
    useMediaUploads,
} from '@/components/media/use-media-uploads';
import type { UploadedBatch } from '@/components/media/use-media-uploads';
import { useWide } from '@/components/media/use-wide';
import { MediaDetailPanel } from '@/components/media-drawer';
import { DetailPage } from '@/components/patterns/detail-page';
import { Button } from '@/components/ui/button';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { TextButton } from '@/components/ui/text-button';
import { useSectionCrumb } from '@/lib/crumbs';
import { __, __n } from '@/lib/i18n';
import { formatInZone } from '@/lib/zoned-time';
import { index as mediaIndex, more as mediaMore } from '@/routes/media';
import type { MediaIndexPage, MediaRow } from '@/types';
/** The address's filter keys; Clear filters drops them and keeps the tab, the order and the layout. */
const FILTER_KEYS = [
    'type',
    'month',
    'search',
    'mine',
    'unused',
    'missing_alt',
    'imported',
    'uploaded_for',
] as const;

type TileSizeKey = keyof typeof TILE_SIZES;

/** Where this browser remembers the tile size: a per-viewer convenience. */
const TILE_KEY = 'teme.media.tile';

function storedTileSize(): TileSizeKey {
    try {
        const stored = window.localStorage.getItem(TILE_KEY);

        return stored === 's' || stored === 'l' ? stored : 'm';
    } catch {
        return 'm';
    }
}

function storeTileSize(size: TileSizeKey): void {
    try {
        window.localStorage.setItem(TILE_KEY, size);
    } catch {
        // Storage refused: the size holds until the page is left.
    }
}

/** A dash that says what it means to a screen reader. */
function Nothing({ meaning }: { meaning: string }) {
    return (
        <>
            <span aria-hidden="true" className="text-ink-2">
                —
            </span>
            <span className="sr-only">{meaning}</span>
        </>
    );
}

/** The file's tile, or its name in the list: where focus returns when its details close. */
function focusFile(id: string): void {
    window.requestAnimationFrame(() =>
        (
            tileOf(id) ??
            document.querySelector<HTMLElement>(
                `[data-media-name="${CSS.escape(id)}"]`,
            )
        )?.focus(),
    );
}

export default function MediaIndex(page: MediaIndexPage) {
    const [selected, setSelected] = useState<string | null>(null);
    const [tileSize, setTileSize] = useState<TileSizeKey>(() =>
        typeof window === 'undefined' ? 'm' : storedTileSize(),
    );
    const wide = useWide();
    const input = useRef<HTMLInputElement>(null);
    const filters = page.filters;
    const tab = filters.tab === 'trash' ? 'trash' : 'all';
    const view = filters.view === 'list' ? 'list' : 'grid';
    const anyFilter = FILTER_KEYS.some((key) => filters[key] !== undefined);
    const contentCrumb = useSectionCrumb('content');
    const { submit } = useHttp();

    setLayoutProps({
        breadcrumbs:
            tab === 'trash'
                ? [
                      contentCrumb,
                      { title: __('Media'), href: mediaIndex() },
                      {
                          title: __('Trash'),
                          href: mediaIndex({ query: { tab: 'trash' } }),
                      },
                  ]
                : [contentCrumb, { title: __('Media'), href: mediaIndex() }],
    });

    // Show more: the files after the first page, kept while the filters stay the same.
    const listKey = JSON.stringify(filters);
    const [more, setMore] = useState<{
        key: string;
        rows: MediaRow[];
        older: string | null;
    }>({ key: listKey, rows: [], older: null });
    const [fetching, setFetching] = useState(false);
    const extra = more.key === listKey ? more.rows : [];
    const firstIds = new Set(page.rows.map((row) => row.id));
    const rows = [
        ...page.rows,
        ...extra.filter((row) => !firstIds.has(row.id)),
    ];
    const older = extra.length > 0 ? more.older : page.older;

    const showMore = async () => {
        if (older === null || fetching) {
            return;
        }

        setFetching(true);

        try {
            const next = (await submit(
                mediaMore({ query: { ...filters, after: older } }),
            )) as { rows: MediaRow[]; older: string | null };
            setMore({
                key: listKey,
                rows: [...extra, ...next.rows],
                older: next.older,
            });
        } finally {
            setFetching(false);
        }
    };

    // Every change starts the list again from its first page.
    const visit = (changes: Record<string, string | undefined>) =>
        router.get(
            mediaIndex().url,
            { ...filters, after: undefined, before: undefined, ...changes },
            { preserveState: true },
        );

    const reload = useCallback(
        () => router.reload({ only: ['rows', 'tabs', 'months'] }),
        [],
    );

    const onBatch = useCallback(
        (batch: UploadedBatch) => {
            reload();
            showToast({ type: 'success', message: uploadedSentence(batch) });
        },
        [reload],
    );

    const uploads = useMediaUploads({ onBatch });

    const { start, stop } = usePoll(
        3000,
        { only: ['rows'] },
        { autoStart: false },
    );
    const pending = rows.some((row) => row.processing === 'pending');

    useEffect(() => {
        if (pending) {
            start();
        } else {
            stop();
        }
    }, [pending, start, stop]);

    const values: MediaFilterValues = {
        type: filters.type ?? '',
        month: filters.month ?? '',
        search: filters.search ?? '',
        mine: filters.mine === '1',
        unused: filters.unused === '1',
        missingAlt: filters.missing_alt === '1',
        imported: filters.imported === '1',
        uploadedFor: filters.uploaded_for ?? '',
    };

    // The bar's values as the address writes them: '' and false leave a key out.
    const filter = (next: Partial<MediaFilterValues>) => {
        const query: Record<string, string | undefined> = {};
        const keys: [keyof MediaFilterValues, string][] = [
            ['type', 'type'],
            ['month', 'month'],
            ['search', 'search'],
            ['mine', 'mine'],
            ['unused', 'unused'],
            ['missingAlt', 'missing_alt'],
            ['imported', 'imported'],
            ['uploadedFor', 'uploaded_for'],
        ];

        for (const [key, name] of keys) {
            const value = next[key];

            if (value !== undefined) {
                query[name] =
                    value === '' || value === false
                        ? undefined
                        : value === true
                          ? '1'
                          : value;
            }
        }

        visit(query);
    };

    const clear = () =>
        visit(Object.fromEntries(FILTER_KEYS.map((key) => [key, undefined])));

    const chooseFiles = () => input.current?.click();

    // The panel's link to every file uploaded for an item: the tab, the order and the layout kept, the other filters dropped (I-553).
    const uploadedForHref = (itemId: string) =>
        mediaIndex({
            query: {
                tab: filters.tab,
                direction: filters.direction,
                view: filters.view,
                uploaded_for: itemId,
            },
        }).url;

    const close = () => {
        const open = selected;
        setSelected(null);

        if (open !== null) {
            focusFile(open);
        }
    };

    const index =
        selected === null ? -1 : rows.findIndex((row) => row.id === selected);
    const step = (by: number) => {
        const next = rows[index + by];

        return index === -1 || next === undefined
            ? null
            : () => setSelected(next.id);
    };

    const details =
        selected === null ? null : (
            <MediaDetailPanel
                key={selected}
                id={selected}
                position={{
                    current: index + 1,
                    total: rows.length,
                }}
                onPrevious={step(-1)}
                onNext={step(1)}
                onClose={close}
                onChanged={reload}
                zone={page.siteTimezone}
                uploadedForHref={uploadedForHref}
                inSheet={!wide}
            />
        );

    // The list view's columns, on the sweep's shared table (I-200: plugins add theirs in phase 5).
    const columns: Column<MediaRow>[] = [
        {
            id: 'file',
            owner: 'core',
            header: __('File'),
            cell: (row) => (
                <div className="flex items-start gap-3">
                    <span className="w-10 shrink-0">
                        <Thumb row={row} />
                    </span>
                    <span className="grid min-w-0">
                        <TextButton
                            data-media-name={row.id}
                            className="text-ink truncate text-start"
                            onClick={() => setSelected(row.id)}
                        >
                            {row.title}
                        </TextButton>
                        <span className="text-ink-3 text-small truncate">
                            {row.filename}
                        </span>
                    </span>
                </div>
            ),
        },
        {
            id: 'type',
            owner: 'core',
            header: __('Type'),
            cell: (row) => (
                <>
                    {mediaKind(row.kind)}
                    <span className="text-ink-3 text-small block">
                        {row.extension.toUpperCase()}
                    </span>
                </>
            ),
        },
        {
            id: 'alt',
            owner: 'core',
            header: __('Alt text'),
            cell: (row) =>
                row.kind !== 'image' ? (
                    <Nothing meaning={__('Not needed')} />
                ) : row.alt ? (
                    <span className="line-clamp-2">{row.alt}</span>
                ) : (
                    <span className="text-warn-ink text-small inline-flex items-center gap-1">
                        <TriangleAlert
                            aria-hidden="true"
                            className="size-3.5"
                        />
                        {__('Missing')}
                    </span>
                ),
        },
        {
            id: 'credit',
            owner: 'core',
            header: __('Credit'),
            // Later (I-201): where the file came from, once plugin sources import into the library.
            cell: (row) =>
                row.credit || row.licence ? (
                    <>
                        {row.credit}
                        {row.licence && (
                            <span className="text-ink-3 text-small block">
                                {row.licence}
                            </span>
                        )}
                    </>
                ) : (
                    <Nothing meaning={__('None')} />
                ),
        },
        {
            id: 'uses',
            owner: 'core',
            header: __('Used in'),
            className: 'text-end tabular-nums',
            cell: (row) =>
                row.uses === 0 ? (
                    row.uses
                ) : (
                    <TextButton
                        aria-label={__n(
                            'Used in :count place: show them|Used in :count places: show them',
                            row.uses,
                        )}
                        onClick={() => setSelected(row.id)}
                    >
                        {row.uses}
                    </TextButton>
                ),
        },
        {
            id: 'uploaded',
            owner: 'core',
            header: __('Uploaded'),
            cell: (row) => (
                <>
                    {row.uploaderName && (
                        <span className="block">{row.uploaderName}</span>
                    )}
                    <span className="text-ink-3 text-small">
                        {formatInZone(row.createdAt, page.siteTimezone)}
                    </span>
                </>
            ),
        },
    ];

    const liveCount =
        page.tabs.find((entry) => entry.key === 'all')?.count ?? 0;
    const trashCount =
        page.tabs.find((entry) => entry.key === 'trash')?.count ?? 0;
    const title = tab === 'trash' ? __('Media trash') : __('Media');

    const viewControls = (
        <>
            {anyFilter && (
                <Button
                    type="button"
                    variant="ghost"
                    data-test="media-clear-filters"
                    onClick={clear}
                >
                    {__('Clear filters')}
                </Button>
            )}
            {view === 'grid' && (
                <Segmented
                    aria-label={__('Tile size')}
                    value={tileSize}
                    onValueChange={(next) => {
                        const size = next as TileSizeKey;
                        setTileSize(size);
                        storeTileSize(size);
                    }}
                >
                    <SegmentedItem value="s" aria-label={__('Small tiles')}>
                        {__('S')}
                    </SegmentedItem>
                    <SegmentedItem value="m" aria-label={__('Medium tiles')}>
                        {__('M')}
                    </SegmentedItem>
                    <SegmentedItem value="l" aria-label={__('Large tiles')}>
                        {__('L')}
                    </SegmentedItem>
                </Segmented>
            )}
            <Segmented
                aria-label={__('View')}
                value={view}
                onValueChange={(next) =>
                    visit({
                        view: next === 'list' ? 'list' : undefined,
                    })
                }
            >
                <SegmentedItem value="grid" data-test="media-layout-grid">
                    <Grid2x2 aria-hidden="true" />
                    {__('Grid')}
                </SegmentedItem>
                <SegmentedItem value="list" data-test="media-layout-list">
                    <List aria-hidden="true" />
                    {__('List')}
                </SegmentedItem>
            </Segmented>
        </>
    );

    return (
        <>
            <Head title={title} />
            <DetailPage
                title={title}
                description={
                    tab === 'trash'
                        ? __n(
                              ':count file in the trash. Restore one and every item that used it shows it again.|:count files in the trash. Restore one and every item that used it shows it again.',
                              trashCount,
                          )
                        : __n(
                              ':count file: pictures, video and documents used across the site.|:count files: pictures, video and documents used across the site.',
                              liveCount,
                          )
                }
                panelLabel={__('File details')}
                actions={
                    <>
                        {tab === 'trash' ? (
                            <Button asChild variant="neutral">
                                <Link
                                    href={
                                        mediaIndex({
                                            query: { view: filters.view },
                                        }).url
                                    }
                                    data-test="media-back"
                                >
                                    {__('Back to media')}
                                </Link>
                            </Button>
                        ) : (
                            <Button asChild variant="ghost">
                                <Link
                                    href={
                                        mediaIndex({
                                            query: {
                                                tab: 'trash',
                                                view: filters.view,
                                            },
                                        }).url
                                    }
                                    data-test="media-trash-view"
                                >
                                    <Trash2 aria-hidden="true" />
                                    {__('Trash')}
                                </Link>
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="primary"
                            data-test="media-upload"
                            onClick={chooseFiles}
                        >
                            <Upload aria-hidden="true" />
                            {__('Upload')}
                        </Button>
                        <input
                            ref={input}
                            data-test="media-upload-input"
                            type="file"
                            multiple
                            hidden
                            onChange={(event) => {
                                uploads.add(event.target.files);
                                // So the same file can be chosen again.
                                event.target.value = '';
                            }}
                        />
                    </>
                }
                lead={
                    <div className="grid gap-4">
                        <UploadList uploads={uploads} />
                        <MediaFilters
                            idPrefix="media-filters"
                            values={values}
                            onChange={filter}
                            months={page.months}
                            chosenItem={page.uploadedFor}
                            showMine={false}
                            showImported={tab === 'all'}
                            counts={tab === 'all' ? page.chips : undefined}
                            end={viewControls}
                        />
                    </div>
                }
                panel={wide ? details : null}
            >
                <div className="grid gap-4">
                    {rows.length === 0 ? (
                        page.filtered ? (
                            <EmptyState
                                kind="nothing-matches"
                                title={__('Nothing matches these filters')}
                                clearHref={
                                    mediaIndex({
                                        query: {
                                            tab: filters.tab,
                                            direction: filters.direction,
                                            view: filters.view,
                                        },
                                    }).url
                                }
                            />
                        ) : tab === 'trash' ? (
                            <EmptyState
                                kind="nothing-yet"
                                title={__('The trash is empty')}
                                description={__(
                                    'Files you move to the trash wait here. Restore one and every item that used it shows it again.',
                                )}
                            />
                        ) : (
                            <EmptyState
                                kind="nothing-yet"
                                title={__('No files yet')}
                                description={__(
                                    'Upload images, video, audio and documents, or drop them anywhere on this page.',
                                )}
                                action={
                                    <Button
                                        type="button"
                                        variant="primary"
                                        onClick={chooseFiles}
                                    >
                                        <Upload aria-hidden="true" />
                                        {__('Upload')}
                                    </Button>
                                }
                            />
                        )
                    ) : view === 'grid' ? (
                        <MediaGrid
                            rows={rows}
                            size={TILE_SIZES[tileSize]}
                            openId={selected}
                            onAction={(id, action) =>
                                (action === 'click' || action === 'enter') &&
                                setSelected(id)
                            }
                            onEscape={selected === null ? undefined : close}
                        />
                    ) : (
                        <DataTable
                            caption={__('Media')}
                            rowKey={(row) => row.id}
                            columns={columns}
                            rows={rows}
                        />
                    )}

                    {older !== null && (
                        <Button
                            type="button"
                            variant="neutral"
                            className="justify-self-center"
                            data-test="media-show-more"
                            disabled={fetching}
                            onClick={() => void showMore()}
                        >
                            {__('Show more')}
                        </Button>
                    )}
                </div>

                {/* Below 1024px the details are a sheet (docs/architecture/design-system.md, section 4.20). */}
                <Sheet
                    open={!wide && selected !== null}
                    onOpenChange={(open) => !open && close()}
                >
                    <SheetContent
                        side="right"
                        aria-describedby={undefined}
                        className="gap-0 p-0"
                        onOpenAutoFocus={(event) => {
                            // The sheet itself, not its first button: Next's tooltip would open by itself (#741).
                            event.preventDefault();
                            (
                                event.currentTarget as HTMLElement | null
                            )?.focus();
                        }}
                        onCloseAutoFocus={(event) => event.preventDefault()}
                    >
                        <SheetTitle className="sr-only">
                            {__('File details')}
                        </SheetTitle>
                        {!wide && details}
                    </SheetContent>
                </Sheet>
                <PageDrop onFiles={uploads.add} />
            </DetailPage>
        </>
    );
}
