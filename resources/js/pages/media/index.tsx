import { Head, router, usePoll } from '@inertiajs/react';
import { Grid2x2, List, Upload } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { showToast } from '@/components/flash-toast';
import { DataTable } from '@/components/list/data-table';
import type { Column } from '@/components/list/data-table';
import { EmptyState } from '@/components/list/empty-state';
import { PageHeader } from '@/components/list/page-header';
import { Pager } from '@/components/list/pager';
import { TabStrip } from '@/components/list/tab-strip';
import { MediaFilters } from '@/components/media/media-filters';
import type { MediaFilterValues } from '@/components/media/media-filters';
import { MediaGrid, Thumb, TILE_SIZES } from '@/components/media/media-grid';
import { MEDIA_KINDS } from '@/components/media/attachment-fields';
import { PageDrop } from '@/components/media/page-drop';
import { UploadList } from '@/components/media/upload-list';
import {
    uploadedSentence,
    useMediaUploads,
} from '@/components/media/use-media-uploads';
import type { UploadedBatch } from '@/components/media/use-media-uploads';
import { MediaDrawer } from '@/components/media-drawer';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';
import { formatInZone } from '@/lib/zoned-time';
import { index as mediaIndex } from '@/routes/media';
import type { MediaIndexPage, MediaRow } from '@/types';

/** The address's filter keys; Clear filters drops them and keeps the tab, the order and the layout. */
const FILTER_KEYS = [
    'type',
    'month',
    'search',
    'mine',
    'unused',
    'missing_alt',
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

export default function MediaIndex(page: MediaIndexPage) {
    const [selected, setSelected] = useState<string | null>(null);
    const [tileSize, setTileSize] = useState<TileSizeKey>(() =>
        typeof window === 'undefined' ? 'm' : storedTileSize(),
    );
    const input = useRef<HTMLInputElement>(null);
    const filters = page.filters;
    const tab = filters.tab === 'trash' ? 'trash' : 'all';
    const view = filters.view === 'list' ? 'list' : 'grid';
    const anyFilter = FILTER_KEYS.some((key) => filters[key] !== undefined);

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
    const pending = page.rows.some((row) => row.processing === 'pending');

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

    // The drawer's link to every file uploaded for an item: the tab, the order and the layout kept, the other filters dropped (I-553).
    const uploadedForHref = (itemId: string) =>
        mediaIndex({
            query: {
                tab: filters.tab,
                direction: filters.direction,
                view: filters.view,
                uploaded_for: itemId,
            },
        }).url;

    // The list view's columns, on the sweep's shared table (I-200: plugins add theirs in phase 5).
    const columns: Column<MediaRow>[] = [
        {
            id: 'file',
            owner: 'core',
            header: 'File',
            cell: (row) => (
                <div className="flex items-start gap-3">
                    <span className="w-10 shrink-0">
                        <Thumb row={row} />
                    </span>
                    <span className="grid min-w-0">
                        <button
                            type="button"
                            className="min-h-6 truncate text-left font-medium underline-offset-2 hover:underline"
                            onClick={() => setSelected(row.id)}
                        >
                            {row.title}
                        </button>
                        <span className="text-ink-2 truncate text-xs">
                            {row.filename}
                        </span>
                    </span>
                </div>
            ),
        },
        {
            id: 'type',
            owner: 'core',
            header: 'Type',
            cell: (row) => (
                <>
                    {MEDIA_KINDS[row.kind]}
                    <span className="text-ink-2 block text-xs">
                        {row.extension.toUpperCase()}
                    </span>
                </>
            ),
        },
        {
            id: 'alt',
            owner: 'core',
            header: 'Alt text',
            cell: (row) =>
                row.kind !== 'image' ? (
                    <Nothing meaning="Not needed" />
                ) : row.alt ? (
                    <span className="line-clamp-2">{row.alt}</span>
                ) : (
                    <Badge variant="destructive">Missing</Badge>
                ),
        },
        {
            id: 'credit',
            owner: 'core',
            header: 'Credit',
            // Later (I-201): where the file came from, once plugin sources import into the library.
            cell: (row) =>
                row.credit || row.licence ? (
                    <>
                        {row.credit}
                        {row.licence && (
                            <span className="text-ink-2 block text-xs">
                                {row.licence}
                            </span>
                        )}
                    </>
                ) : (
                    <Nothing meaning="None" />
                ),
        },
        {
            id: 'uses',
            owner: 'core',
            header: 'Used in',
            className: 'text-right tabular-nums',
            cell: (row) => row.uses,
        },
        {
            id: 'uploaded',
            owner: 'core',
            header: 'Uploaded',
            cell: (row) => (
                <>
                    {row.uploaderName && (
                        <span className="block">{row.uploaderName}</span>
                    )}
                    <span className="text-ink-2 text-xs">
                        {formatInZone(row.createdAt, page.siteTimezone)}
                    </span>
                </>
            ),
        },
    ];

    return (
        <>
            <Head title="Media" />
            <div className="flex flex-1 flex-col gap-4 p-6">
                <PageHeader
                    title="Media"
                    description="Every file the site uses."
                    action={
                        <>
                            <Button
                                type="button"
                                data-test="media-upload"
                                onClick={chooseFiles}
                            >
                                <Upload aria-hidden="true" />
                                Upload files
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
                />

                <UploadList uploads={uploads} />

                <TabStrip
                    label="Views"
                    current={tab}
                    preserveState
                    tabs={page.tabs.map((entry) => ({
                        key: entry.key,
                        label: entry.label,
                        count: entry.count,
                        href: mediaIndex({
                            query: {
                                ...filters,
                                after: undefined,
                                before: undefined,
                                tab:
                                    entry.key === 'all' ? undefined : entry.key,
                            },
                        }),
                    }))}
                />

                <MediaFilters
                    idPrefix="media-filters"
                    values={values}
                    onChange={filter}
                    months={page.months}
                    chosenItem={page.uploadedFor}
                />

                <div className="flex flex-wrap items-center gap-2">
                    {anyFilter && (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            data-test="media-clear-filters"
                            onClick={clear}
                        >
                            Clear filters
                        </Button>
                    )}
                    <span className="flex-1" />
                    {/* The trash is always newest first, by when each file went there. */}
                    {tab === 'all' && (
                        <SelectField
                            aria-label="Order"
                            value={filters.direction === 'asc' ? 'asc' : 'desc'}
                            onValueChange={(direction) =>
                                visit({
                                    direction:
                                        direction === 'asc' ? 'asc' : undefined,
                                })
                            }
                            options={[
                                { value: 'desc', label: 'Newest first' },
                                { value: 'asc', label: 'Oldest first' },
                            ]}
                        />
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
                            <SegmentedItem
                                value="s"
                                aria-label={__('Small tiles')}
                            >
                                {__('S')}
                            </SegmentedItem>
                            <SegmentedItem
                                value="m"
                                aria-label={__('Medium tiles')}
                            >
                                {__('M')}
                            </SegmentedItem>
                            <SegmentedItem
                                value="l"
                                aria-label={__('Large tiles')}
                            >
                                {__('L')}
                            </SegmentedItem>
                        </Segmented>
                    )}
                    <div
                        role="group"
                        aria-label="Layout"
                        className="flex gap-1"
                    >
                        <Button
                            type="button"
                            size="sm"
                            variant={view === 'grid' ? 'secondary' : 'ghost'}
                            aria-pressed={view === 'grid'}
                            data-test="media-layout-grid"
                            onClick={() => visit({ view: undefined })}
                        >
                            <Grid2x2 aria-hidden="true" />
                            Grid
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant={view === 'list' ? 'secondary' : 'ghost'}
                            aria-pressed={view === 'list'}
                            data-test="media-layout-list"
                            onClick={() => visit({ view: 'list' })}
                        >
                            <List aria-hidden="true" />
                            List
                        </Button>
                    </div>
                </div>

                {page.rows.length === 0 ? (
                    page.filtered ? (
                        <EmptyState
                            kind="nothing-matches"
                            title="Nothing matches these filters"
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
                            title="The trash is empty"
                            description="Files you move to the trash wait here. Restore one and every item that used it shows it again."
                        />
                    ) : (
                        <EmptyState
                            kind="nothing-yet"
                            title="No files yet"
                            description="Upload images, video, audio and documents, or drop them anywhere on this page."
                            action={
                                <Button type="button" onClick={chooseFiles}>
                                    Upload files
                                </Button>
                            }
                        />
                    )
                ) : view === 'grid' ? (
                    <MediaGrid
                        rows={page.rows}
                        size={TILE_SIZES[tileSize]}
                        openId={selected}
                        onAction={(id, action) =>
                            (action === 'click' || action === 'enter') &&
                            setSelected(id)
                        }
                    />
                ) : (
                    <DataTable
                        caption="Media"
                        rowKey={(row) => row.id}
                        columns={columns}
                        rows={page.rows}
                    />
                )}

                <Pager
                    preserveState
                    newer={
                        page.newer
                            ? mediaIndex({
                                  query: { ...filters, before: page.newer },
                              }).url
                            : null
                    }
                    older={
                        page.older
                            ? mediaIndex({
                                  query: { ...filters, after: page.older },
                              }).url
                            : null
                    }
                />
            </div>
            <MediaDrawer
                id={selected}
                onClose={() => setSelected(null)}
                onChanged={reload}
                zone={page.siteTimezone}
                uploadedForHref={uploadedForHref}
            />
            <PageDrop onFiles={uploads.add} />
        </>
    );
}
