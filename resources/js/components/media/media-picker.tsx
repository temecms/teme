import { router, useHttp } from '@inertiajs/react';
import { Upload, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { CSSProperties } from 'react';
import { createRoot } from 'react-dom/client';
import { AttachmentDetails } from '@/components/media/attachment-details';
import { MediaFilters } from '@/components/media/media-filters';
import type { MediaFilterValues } from '@/components/media/media-filters';
import { MediaGrid, Thumb } from '@/components/media/media-grid';
import type { TileAction } from '@/components/media/media-grid';
import { UploadList } from '@/components/media/upload-list';
import {
    uploadedSentence,
    useMediaUploads,
} from '@/components/media/use-media-uploads';
import type { UploadedBatch } from '@/components/media/use-media-uploads';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { __, __n } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { picker as mediaPicker, show as showMedia } from '@/routes/media';
import type { MediaKind, MediaMonth, MediaRow, UploadTarget } from '@/types';

/** What the picker is opened for: it sets the title and the main button's words (DS 4.38). */
export type MediaPickerCaller =
    | 'insert'
    | 'replace'
    | 'featured'
    | 'gallery'
    | 'choose';

export type MediaPickerOptions = {
    caller: MediaPickerCaller;
    /** Several may be chosen; they reach onInsert in the order chosen. */
    multi: boolean;
    /** Called once, with at least one file, when the main button is pressed. */
    onInsert: (ids: string[], rows: MediaRow[]) => void;
    /** The kinds the grid offers; images when left out. */
    kinds?: MediaKind[];
    /** The main button's words where the caller's differ ("Use as poster"); required for 'choose'. */
    action?: string;
    /** The item open in the editor, which an upload made here records (I-553). */
    uploadedFor?: UploadTarget | null;
    /** Called when it closes without a choice. */
    onClose?: () => void;
};

type PickerPage = {
    rows: MediaRow[];
    older: string | null;
    months: MediaMonth[];
};

const NO_FILTERS: MediaFilterValues = {
    type: '',
    month: '',
    search: '',
    mine: false,
    unused: false,
    missingAlt: false,
    uploadedFor: '',
};

/** The picker's tile width (docs/architecture/design-system.md, section 4.35). */
const PICKER_TILE = { wide: 160, phone: 148 };

/** The body's two columns, and its two rows on a phone, where the side goes under the grid. */
const BODY_STYLE = {
    '--picker-cols': 'minmax(0, 1fr) 300px',
    '--picker-rows': 'minmax(0, 3fr) minmax(0, 2fr)',
} as CSSProperties;

/** The picker's title for its caller (docs/architecture/design-system.md, section 4.38). */
function titleFor(caller: MediaPickerCaller, kinds: MediaKind[]): string {
    switch (caller) {
        case 'insert':
            return __('Insert an image');
        case 'replace':
            return __('Replace the image');
        case 'featured':
            return __('Choose the featured image');
        case 'gallery':
            return __('Add images to the gallery');
        default:
            return kinds.length === 1 && kinds[0] === 'image'
                ? __('Choose an image')
                : __('Choose a file');
    }
}

/** The main button's words for its caller and how many are chosen; the caller's own words win. */
function actionFor(
    caller: MediaPickerCaller,
    chosen: number,
    action: string | undefined,
): string {
    if (action !== undefined) {
        return action;
    }

    switch (caller) {
        case 'insert':
            return __('Insert image');
        case 'replace':
            return __('Replace');
        case 'featured':
            return __('Set featured image');
        case 'gallery':
            return __n('Add image|Add :count images', Math.max(chosen, 1));
        default:
            return __('Choose');
    }
}

type DialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    caller: MediaPickerCaller;
    multi: boolean;
    kinds: MediaKind[];
    action?: string;
    uploadedFor: UploadTarget | null;
    /** The main button, Enter or a double-click: the caller hears the choice, then closes the picker. */
    onInsert: (ids: string[], rows: MediaRow[]) => void;
    /** Where focus returns on closing; what had focus when the dialog mounted when left out. */
    opener?: HTMLElement | null;
};

/** The element with focus, if any. */
function focusedElement(): HTMLElement | null {
    return document.activeElement instanceof HTMLElement
        ? document.activeElement
        : null;
}

/**
 * The one media picker (docs/architecture/design-system.md, section 4.38;
 * I-3400): the large fixed dialog with its Library and Upload tabs, the
 * chosen file's fields at the side, and a tray of what is chosen, in the
 * order chosen.
 */
// Later (I-201): sources registered by plugins add tabs here, drawing the
// same grid and details; choosing imports into the library first.
export function MediaPickerDialog({
    open,
    onOpenChange,
    caller,
    multi,
    kinds,
    action,
    uploadedFor,
    onInsert,
    opener,
}: DialogProps) {
    const { submit } = useHttp();
    const [tab, setTab] = useState<'library' | 'upload'>('library');
    const [rows, setRows] = useState<MediaRow[]>([]);
    const [older, setOlder] = useState<string | null>(null);
    const [months, setMonths] = useState<MediaMonth[]>([]);
    const [values, setValues] = useState<MediaFilterValues>(NO_FILTERS);
    const [chosen, setChosen] = useState<string[]>([]);
    const [sideId, setSideId] = useState<string | null>(null);
    const [known, setKnown] = useState<Record<string, MediaRow>>({});
    const [uploaded, setUploaded] = useState<string | null>(null);
    const [dragOver, setDragOver] = useState(false);
    const [dropping, setDropping] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    // What had focus when the picker opened: Radix returns focus only to a
    // DialogTrigger, and the picker is opened by a call, not by a trigger.
    const [mountedFrom] = useState(focusedElement);
    const returnTo = opener === undefined ? mountedFrom : opener;
    // Callers write their kinds inline, a new array each render: the request depends on their names.
    const kindNames = kinds.join(',');
    const imagesOnly = kinds.length === 1 && kinds[0] === 'image';
    // Every filter but Uploaded for, which every file uploaded here matches (I-553).
    const otherFiltersOn =
        values.type !== '' ||
        values.month !== '' ||
        values.search !== '' ||
        values.unused ||
        values.missingAlt;
    const filtersOn = otherFiltersOn || values.uploadedFor !== '';

    const remember = useCallback((found: MediaRow[]) => {
        setKnown((current) => ({
            ...current,
            ...Object.fromEntries(found.map((row) => [row.id, row])),
        }));
    }, []);

    const load = useCallback(
        async (after: string | null): Promise<void> => {
            const page = (await submit(
                mediaPicker({
                    query: {
                        kinds:
                            values.type !== ''
                                ? [values.type]
                                : kindNames.split(','),
                        search: values.search || undefined,
                        month: values.month || undefined,
                        missingAlt: values.missingAlt || undefined,
                        unused: values.unused || undefined,
                        uploadedFor: values.uploadedFor || undefined,
                        after: after ?? undefined,
                    },
                }),
            )) as PickerPage;
            setRows((current) =>
                after === null ? page.rows : [...current, ...page.rows],
            );
            remember(page.rows);
            setOlder(page.older);
            setMonths(page.months);
        },
        [submit, kindNames, values, remember],
    );

    useEffect(() => {
        if (!open) {
            return;
        }

        const timer = setTimeout(() => void load(null), 200);

        return () => clearTimeout(timer);
    }, [open, load]);

    const onBatch = useCallback(
        (batch: UploadedBatch) => {
            // The new files are chosen, the last alone when only one may be.
            const picked = multi ? batch.ids : batch.ids.slice(-1);
            setChosen(picked);
            setSideId(picked[picked.length - 1] ?? null);
            setUploaded(uploadedSentence(batch));
            setTab('library');

            // Cleared, so the new files show: loading follows the filters' change.
            // Uploaded for stays, since every file uploaded here matches it.
            if (otherFiltersOn) {
                setValues({ ...NO_FILTERS, uploadedFor: values.uploadedFor });
            } else {
                void load(null);
            }
        },
        [otherFiltersOn, load, multi, values.uploadedFor],
    );

    // The file the library already had: fetched and put in the grid first, so a choice is
    // never missing from the rows onInsert hands its caller.
    const onExisting = useCallback(
        async (id: string) => {
            let existing: MediaRow;

            try {
                existing = (await submit(showMedia({ media: id }))) as MediaRow;
            } catch {
                // Gone, or not allowed: nothing is chosen.
                return;
            }

            remember([existing]);
            setRows((current) =>
                current.some((row) => row.id === id)
                    ? current
                    : [existing, ...current],
            );
            setChosen((current) =>
                multi ? [...current.filter((each) => each !== id), id] : [id],
            );
            setSideId(id);
            setUploaded(null);
        },
        [multi, remember, submit],
    );

    const uploads = useMediaUploads({
        onBatch,
        onExisting,
        uploadedFor: uploadedFor?.id ?? null,
    });

    const insert = (ids: string[]) => {
        const picked = ids.filter((id) => known[id] !== undefined);

        if (picked.length === 0) {
            return;
        }

        onInsert(
            picked,
            picked.map((id) => known[id]),
        );
    };

    const toggle = (id: string) => {
        setSideId(id);
        setUploaded(null);

        if (!multi) {
            setChosen([id]);

            return;
        }

        setChosen((current) =>
            current.includes(id)
                ? current.filter((each) => each !== id)
                : [...current, id],
        );
    };

    const onTile = (id: string, how: TileAction) => {
        if (how === 'click' || how === 'space') {
            toggle(id);

            return;
        }

        if (how === 'double' && multi) {
            return;
        }

        // Enter, or a double-click in a single choice: chosen and inserted at once.
        if (multi && how === 'enter') {
            insert(chosen.length > 0 ? chosen : [id]);

            return;
        }

        setChosen([id]);
        insert([id]);
    };

    const remove = (id: string) =>
        setChosen((current) => current.filter((each) => each !== id));

    const status =
        uploads.pending > 0
            ? __n('Uploading 1 file…|Uploading :count files…', uploads.pending)
            : uploaded !== null
              ? uploaded
              : chosen.length > 0
                ? __n('1 file selected|:count files selected', chosen.length)
                : multi
                  ? __(
                        'Select one or more files. Space selects; Enter confirms.',
                    )
                  : __('Select a file. Space selects; Enter confirms.');

    const side =
        sideId !== null && chosen.includes(sideId)
            ? sideId
            : (chosen[chosen.length - 1] ?? null);

    const hint = imagesOnly
        ? multi
            ? __(
                  'Only images can be used here. Choose several; they go in the order you choose them.',
              )
            : __(
                  'Only images can be used here. Double-click to insert at once.',
              )
        : null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                size="xl"
                aria-describedby={undefined}
                className="gap-0 p-0"
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    returnTo?.focus();
                }}
                onDragOver={(event) => {
                    event.preventDefault();
                    setDropping(true);
                }}
                onDragLeave={() => setDropping(false)}
                onDrop={(event) => {
                    event.preventDefault();
                    setDropping(false);
                    setDragOver(false);
                    uploads.add(event.dataTransfer.files);
                }}
            >
                <Tabs
                    value={tab}
                    onValueChange={(next) =>
                        setTab(next === 'upload' ? 'upload' : 'library')
                    }
                    className="flex min-h-0 flex-1 flex-col gap-0"
                >
                    <div className="flex flex-wrap items-center gap-x-6 gap-y-2 ps-6 pe-12 pt-4 pb-3">
                        <DialogTitle>{titleFor(caller, kinds)}</DialogTitle>
                        <TabsList>
                            <TabsTrigger value="library">
                                {__('Library')}
                            </TabsTrigger>
                            <TabsTrigger value="upload">
                                {__('Upload')}
                            </TabsTrigger>
                        </TabsList>
                    </div>

                    <div
                        className="grid min-h-0 flex-1 max-md:grid-rows-(--picker-rows) md:grid-cols-(--picker-cols)"
                        style={BODY_STYLE}
                    >
                        <ScrollArea className="px-6 pt-2 pb-6">
                            <TabsContent value="library" className="grid gap-4">
                                <MediaFilters
                                    idPrefix="media-modal-filters"
                                    values={values}
                                    onChange={(changes) =>
                                        setValues((current) => ({
                                            ...current,
                                            ...changes,
                                        }))
                                    }
                                    months={months}
                                    kinds={kinds}
                                    showMine={false}
                                    openItem={uploadedFor}
                                />
                                <UploadList uploads={uploads} />
                                {hint !== null && (
                                    <p className="text-small text-ink-3">
                                        {hint}
                                    </p>
                                )}
                                {rows.length === 0 &&
                                    (filtersOn ? (
                                        <div className="text-small grid justify-items-start gap-2">
                                            <p className="text-ink-2">
                                                {!otherFiltersOn &&
                                                uploadedFor !== null
                                                    ? __(
                                                          'Nothing has been uploaded for this :noun yet.',
                                                          {
                                                              noun: uploadedFor.noun,
                                                          },
                                                      )
                                                    : __(
                                                          'Nothing in the library matches these filters.',
                                                      )}
                                            </p>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="neutral"
                                                onClick={() =>
                                                    setValues(NO_FILTERS)
                                                }
                                            >
                                                {__('Clear filters')}
                                            </Button>
                                        </div>
                                    ) : (
                                        <p className="text-small text-ink-2">
                                            {__(
                                                'Nothing here yet. Upload files, or drop them here.',
                                            )}
                                        </p>
                                    ))}
                                <MediaGrid
                                    rows={rows}
                                    size={PICKER_TILE}
                                    selectedIds={chosen}
                                    picker
                                    multiselectable={multi}
                                    onAction={onTile}
                                />
                                {older !== null && (
                                    <Button
                                        type="button"
                                        variant="neutral"
                                        size="sm"
                                        className="justify-self-start"
                                        onClick={() => void load(older)}
                                    >
                                        {__('Show more')}
                                    </Button>
                                )}
                            </TabsContent>
                            <TabsContent value="upload" className="grid gap-4">
                                <div
                                    data-test="media-modal-drop-zone"
                                    className={cn(
                                        'bg-surface-2 ring-surface-4 grid min-h-80 content-center justify-items-center gap-3 rounded-lg p-6 text-center ring-2 ring-inset',
                                        dragOver &&
                                            'bg-accent-soft ring-accent',
                                    )}
                                    onDragEnter={() => setDragOver(true)}
                                    onDragLeave={() => setDragOver(false)}
                                >
                                    <Upload
                                        aria-hidden="true"
                                        className="text-ink-3 size-8"
                                    />
                                    <p className="text-body font-semibold">
                                        {__('Drop files here')}
                                    </p>
                                    <p className="text-small text-ink-2">
                                        {__(
                                            'They go into the library and are chosen here.',
                                        )}
                                    </p>
                                    <Button
                                        type="button"
                                        onClick={() => input.current?.click()}
                                    >
                                        {__('Choose files')}
                                    </Button>
                                </div>
                                <UploadList uploads={uploads} />
                            </TabsContent>
                        </ScrollArea>

                        <ScrollArea className="bg-surface-2 max-md:border-edge p-5 max-md:border-t">
                            <section
                                aria-label={__('File details')}
                                data-test="media-modal-details"
                                className="grid content-start gap-4"
                            >
                                {side === null ? (
                                    <p className="text-small text-ink-3">
                                        {__(
                                            'Select a file to see its details.',
                                        )}
                                    </p>
                                ) : (
                                    <AttachmentDetails
                                        key={side}
                                        id={side}
                                        onChanged={(details) => {
                                            remember([details]);
                                            setRows((current) =>
                                                current.map((row) =>
                                                    row.id === details.id
                                                        ? {
                                                              ...row,
                                                              title: details.title,
                                                              alt: details.alt,
                                                              hasAlt:
                                                                  (
                                                                      details.alt ??
                                                                      ''
                                                                  ).trim() !==
                                                                  '',
                                                          }
                                                        : row,
                                                ),
                                            );
                                        }}
                                    />
                                )}
                            </section>
                        </ScrollArea>
                    </div>
                </Tabs>

                <div className="border-edge flex flex-wrap items-center gap-x-4 gap-y-2 border-t ps-6 pe-4 pt-3 pb-3">
                    <section
                        aria-label={__('Selected')}
                        className="flex min-w-0 flex-1 flex-wrap items-center gap-3"
                    >
                        <span className="text-small text-ink-2">
                            {chosen.length > 0
                                ? __('Selected (:count)', {
                                      count: chosen.length,
                                  })
                                : __('Nothing selected yet')}
                        </span>
                        {chosen.length > 0 && (
                            <ul
                                data-test="media-modal-tray"
                                className="flex flex-wrap gap-3"
                            >
                                {chosen.map((id) => {
                                    const row = known[id];

                                    return (
                                        <li
                                            key={id}
                                            data-media-id={id}
                                            className="relative size-12"
                                        >
                                            {row !== undefined && (
                                                <Thumb row={row} />
                                            )}
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-xs"
                                                aria-label={__(
                                                    'Remove :name from the selection',
                                                    {
                                                        name: row?.title ?? '',
                                                    },
                                                )}
                                                className="absolute -end-3 -top-3 size-6 rounded-full p-0 hover:bg-transparent"
                                                onClick={() => remove(id)}
                                            >
                                                <span className="bg-ink text-page flex size-5 items-center justify-center rounded-full">
                                                    <X
                                                        aria-hidden="true"
                                                        className="size-3"
                                                    />
                                                </span>
                                            </Button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                        <span
                            role="status"
                            data-test="media-modal-status"
                            className="text-small text-ink-2"
                        >
                            {status}
                        </span>
                    </section>
                    {chosen.length > 0 && (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            data-test="media-modal-clear"
                            onClick={() => {
                                setChosen([]);
                                setUploaded(null);
                            }}
                        >
                            {__('Clear')}
                        </Button>
                    )}
                    <Button
                        type="button"
                        variant="neutral"
                        data-test="media-modal-cancel"
                        onClick={() => onOpenChange(false)}
                    >
                        {__('Cancel')}
                    </Button>
                    <Button
                        type="button"
                        disabled={chosen.length === 0}
                        onClick={() => insert(chosen)}
                        data-test="media-modal-use"
                    >
                        {actionFor(caller, chosen.length, action)}
                    </Button>
                </div>

                {/* Mounted always, outside the tabs, so Choose files and the tests reach it. */}
                <input
                    ref={input}
                    data-test="media-modal-upload-input"
                    type="file"
                    multiple
                    hidden
                    onChange={(event) => {
                        uploads.add(event.target.files);
                        event.target.value = '';
                    }}
                />

                {dropping && (
                    <div
                        aria-hidden="true"
                        className="bg-page/90 border-accent text-body pointer-events-none absolute inset-0 z-(--z-sticky) flex items-center justify-center rounded-xl border-2 border-dashed font-medium"
                    >
                        {__('Drop files to upload')}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

let show: ((options: MediaPickerOptions) => void) | null = null;
let waiting: MediaPickerOptions | null = null;

/** The dialog's props for a call's options: images unless the caller names its kinds. */
function pickerProps(
    options: MediaPickerOptions,
): Omit<DialogProps, 'open' | 'onOpenChange' | 'onInsert'> {
    return {
        caller: options.caller,
        multi: options.multi,
        kinds: options.kinds ?? ['image'],
        action: options.action,
        uploadedFor: options.uploadedFor ?? null,
    };
}

/** Opens the one media picker over the current screen. The caller mounts nothing; focus returns to what had it. */
export function openMediaPicker(options: MediaPickerOptions): void {
    if (show !== null) {
        show(options);

        return;
    }

    waiting = options;

    // The first call mounts the one host; it lives as long as the page.
    const mounted = [...document.body.children].some(
        (element) =>
            element instanceof HTMLElement &&
            element.dataset.mediaPickerHost !== undefined,
    );

    if (!mounted) {
        const holder = document.createElement('div');
        holder.dataset.mediaPickerHost = '';
        document.body.append(holder);
        createRoot(holder).render(<MediaPickerHost />);
    }
}

function MediaPickerHost() {
    const [opened, setOpened] = useState<{
        options: MediaPickerOptions;
        key: number;
        /** What had focus when the picker went from closed to open, kept while options are replaced. */
        opener: HTMLElement | null;
    } | null>(() => {
        // Built before waiting is cleared: the minifier folds a copy read
        // after the clear into the cleared value.
        if (waiting === null) {
            return null;
        }

        const first = { options: waiting, key: 1, opener: focusedElement() };
        waiting = null;

        return first;
    });

    useEffect(() => {
        show = (options) => {
            const focused = focusedElement();

            setOpened((current) => ({
                options,
                key: (current?.key ?? 0) + 1,
                opener: current === null ? focused : current.opener,
            }));
        };

        return () => {
            show = null;
        };
    }, []);

    useEffect(() => router.on('start', () => setOpened(null)), []);

    if (opened === null) {
        return null;
    }

    const close = () => {
        setOpened(null);
        opened.options.onClose?.();
    };

    return (
        <MediaPickerDialog
            key={opened.key}
            open
            opener={opened.opener}
            onOpenChange={(open) => !open && close()}
            onInsert={(ids, rows) => {
                opened.options.onInsert(ids, rows);
                setOpened(null);
            }}
            {...pickerProps(opened.options)}
        />
    );
}
