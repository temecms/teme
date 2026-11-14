import { useUploadTarget } from '@/components/editor/cards/card-environment';
import { useHttp } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { AttachmentDetails } from '@/components/media/attachment-details';
import { MediaFilters } from '@/components/media/media-filters';
import type { MediaFilterValues } from '@/components/media/media-filters';
import { MediaGrid } from '@/components/media/media-grid';
import { UploadList } from '@/components/media/upload-list';
import {
    uploadedSentence,
    useMediaUploads,
} from '@/components/media/use-media-uploads';
import type { UploadedBatch } from '@/components/media/use-media-uploads';
import { MediaDrawer } from '@/components/media-drawer';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { picker as mediaPicker, show as showMedia } from '@/routes/media';
import type {
    MediaDetailsData,
    MediaKind,
    MediaMonth,
    MediaRow,
} from '@/types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    kinds: MediaKind[];
    multiple?: boolean;
    action: string;
    onChoose: (ids: string[], rows: MediaRow[]) => void;
};

type PickerPage = {
    rows: MediaRow[];
    older: string | null;
    months: MediaMonth[];
    siteTimezone: string;
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

// Later (I-201): sources registered by plugins add tabs here, drawing the
// same grid and details; choosing imports into the library first.
export function MediaModal({
    open,
    onOpenChange,
    kinds,
    multiple = false,
    action,
    onChoose,
}: Props) {
    const { submit } = useHttp();
    const openItem = useUploadTarget();
    const [rows, setRows] = useState<MediaRow[]>([]);
    const [older, setOlder] = useState<string | null>(null);
    const [months, setMonths] = useState<MediaMonth[]>([]);
    const [zone, setZone] = useState('UTC');
    const [values, setValues] = useState<MediaFilterValues>(NO_FILTERS);
    const [selectedIds, setSelectedIds] = useState<string[]>([]);
    const [focusedId, setFocusedId] = useState<string | null>(null);
    const [editingId, setEditingId] = useState<string | null>(null);
    const [version, setVersion] = useState(0);
    const [uploaded, setUploaded] = useState<string | null>(null);
    const [dragOver, setDragOver] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    // Callers write their kinds inline, a new array each render: the request depends on their names.
    const kindNames = kinds.join(',');
    // Every filter but Uploaded for, which every file uploaded here matches (I-553).
    const otherFiltersOn =
        values.type !== '' ||
        values.month !== '' ||
        values.search !== '' ||
        values.unused ||
        values.missingAlt;
    const filtersOn = otherFiltersOn || values.uploadedFor !== '';

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
            setOlder(page.older);
            setMonths(page.months);
            setZone(page.siteTimezone);
        },
        [submit, kindNames, values],
    );

    useEffect(() => {
        if (!open) {
            return;
        }

        const timer = setTimeout(() => void load(null), 200);

        return () => clearTimeout(timer);
    }, [open, load]);

    // A window opened again starts with nothing chosen.
    useEffect(() => {
        if (open) {
            setSelectedIds([]);
            setFocusedId(null);
            setUploaded(null);
            setValues((current) => ({ ...current, uploadedFor: '' }));
        }
    }, [open]);

    const onBatch = useCallback(
        (batch: UploadedBatch) => {
            // The new files are chosen, the last alone when only one may be, as the build chose them.
            const chosen = multiple ? batch.ids : batch.ids.slice(-1);
            setSelectedIds(chosen);
            setFocusedId(chosen[chosen.length - 1] ?? null);
            setUploaded(uploadedSentence(batch));

            // Cleared, so the new files show: loading follows the filters' change.
            // Uploaded for stays, since every file uploaded here matches it.
            if (otherFiltersOn) {
                setValues({ ...NO_FILTERS, uploadedFor: values.uploadedFor });
            } else {
                void load(null);
            }
        },
        [otherFiltersOn, load, multiple, values.uploadedFor],
    );

    // The file the library already had: fetched and put in the grid first, so a choice is
    // never missing from the rows onChoose hands its caller.
    const onExisting = useCallback(
        async (id: string) => {
            let existing: MediaRow;

            try {
                existing = (await submit(showMedia({ media: id }))) as MediaRow;
            } catch {
                // Gone, or not allowed: nothing is chosen.
                return;
            }

            setRows((current) =>
                current.some((row) => row.id === id)
                    ? current
                    : [existing, ...current],
            );
            setSelectedIds((current) =>
                multiple
                    ? [...current.filter((each) => each !== id), id]
                    : [id],
            );
            setFocusedId(id);
            setUploaded(null);
        },
        [multiple, submit],
    );

    const uploads = useMediaUploads({
        onBatch,
        onExisting,
        uploadedFor: openItem?.id ?? null,
    });

    const choose = (id: string) => {
        setFocusedId(id);
        setUploaded(null);

        if (!multiple) {
            setSelectedIds([id]);

            return;
        }

        setSelectedIds((current) =>
            current.includes(id)
                ? current.filter((existing) => existing !== id)
                : [...current, id],
        );
    };

    const commit = () => {
        if (selectedIds.length === 0) {
            return;
        }

        onChoose(
            selectedIds,
            rows.filter((row) => selectedIds.includes(row.id)),
        );
        onOpenChange(false);
    };

    // A change in the drawer opened over this window: the grid and the details read again,
    // so the details never hold, or save back, what the drawer replaced.
    const changed = (details: MediaDetailsData | null) => {
        const gone =
            details === null ? editingId : details.trashed ? details.id : null;
        setVersion((current) => current + 1);
        void load(null);

        // Deleted, or moved to the trash: no longer something to choose.
        if (gone !== null) {
            setSelectedIds((current) => current.filter((id) => id !== gone));
            setFocusedId((current) => (current === gone ? null : current));
        }
    };

    const status =
        uploads.pending > 0
            ? uploads.pending === 1
                ? 'Uploading 1 file…'
                : `Uploading ${uploads.pending} files…`
            : uploaded !== null
              ? uploaded
              : selectedIds.length === 1
                ? '1 file selected'
                : selectedIds.length > 1
                  ? `${selectedIds.length} files selected`
                  : multiple
                    ? 'Select one or more files. Space selects; Enter confirms.'
                    : 'Select a file. Space selects; Enter confirms.';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="flex max-h-[85vh] max-w-5xl flex-col overflow-hidden p-0 sm:max-w-5xl"
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragOver(true);
                }}
                onDragLeave={() => setDragOver(false)}
                onDrop={(event) => {
                    event.preventDefault();
                    setDragOver(false);
                    uploads.add(event.dataTransfer.files);
                }}
            >
                <DialogHeader className="border-b p-4">
                    <DialogTitle>{action}</DialogTitle>
                    <div className="flex items-end justify-between gap-2">
                        <nav className="flex gap-2 text-sm">
                            <span className="border-primary text-foreground border-b-2 pb-1 font-medium">
                                Library
                            </span>
                        </nav>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => input.current?.click()}
                        >
                            <Upload aria-hidden="true" />
                            Upload files
                        </Button>
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
                    </div>
                </DialogHeader>

                <div className="grid gap-3 border-b p-4">
                    <MediaFilters
                        idPrefix="media-modal-filters"
                        values={values}
                        onChange={(changes) =>
                            setValues((current) => ({ ...current, ...changes }))
                        }
                        months={months}
                        kinds={kinds}
                        showMine={false}
                        openItem={openItem}
                    />
                    <UploadList uploads={uploads} />
                </div>

                <div className="relative flex flex-1 overflow-hidden">
                    {dragOver && (
                        <div className="bg-background/90 absolute inset-0 z-10 flex items-center justify-center border-2 border-dashed text-sm font-medium">
                            Drop files to upload
                        </div>
                    )}
                    <div
                        className="flex-1 overflow-y-auto p-4"
                        onKeyDown={(event) => {
                            const index = rows.findIndex(
                                (row) => row.id === focusedId,
                            );

                            if (
                                event.key === 'ArrowRight' ||
                                event.key === 'ArrowDown'
                            ) {
                                event.preventDefault();
                                const next =
                                    rows[Math.min(rows.length - 1, index + 1)];

                                if (next) {
                                    setFocusedId(next.id);
                                }
                            } else if (
                                event.key === 'ArrowLeft' ||
                                event.key === 'ArrowUp'
                            ) {
                                event.preventDefault();
                                const previous = rows[Math.max(0, index - 1)];

                                if (previous) {
                                    setFocusedId(previous.id);
                                }
                            } else if (event.key === ' ' && focusedId) {
                                event.preventDefault();
                                choose(focusedId);
                            } else if (event.key === 'Enter' && focusedId) {
                                event.preventDefault();
                                choose(focusedId);
                                commit();
                            }
                        }}
                    >
                        {rows.length === 0 &&
                            (filtersOn ? (
                                <div className="grid justify-items-start gap-2 text-sm">
                                    <p className="text-muted-foreground">
                                        {!otherFiltersOn && openItem !== null
                                            ? `Nothing has been uploaded for this ${openItem.noun} yet.`
                                            : 'Nothing in the library matches these filters.'}
                                    </p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setValues(NO_FILTERS)}
                                    >
                                        Clear filters
                                    </Button>
                                </div>
                            ) : (
                                <p className="text-muted-foreground text-sm">
                                    Nothing here yet. Upload files, or drop them
                                    here.
                                </p>
                            ))}
                        <MediaGrid
                            rows={rows}
                            selectedIds={selectedIds}
                            focusedId={focusedId}
                            multiselectable={multiple}
                            onChoose={choose}
                        />
                        {older !== null && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="mt-3"
                                onClick={() => void load(older)}
                            >
                                Show more
                            </Button>
                        )}
                    </div>
                    <section
                        aria-label="File details"
                        data-test="media-modal-details"
                        className="w-72 shrink-0 overflow-y-auto border-l"
                    >
                        {focusedId === null ? (
                            <p className="text-muted-foreground p-4 text-sm">
                                Select a file to see its details.
                            </p>
                        ) : (
                            <AttachmentDetails
                                id={focusedId}
                                version={version}
                                onEdit={setEditingId}
                            />
                        )}
                    </section>
                </div>

                <div className="flex flex-wrap items-center gap-2 border-t p-4">
                    <span
                        role="status"
                        data-test="media-modal-status"
                        className="text-muted-foreground text-sm"
                    >
                        {status}
                    </span>
                    {selectedIds.length > 0 && (
                        <Button
                            type="button"
                            size="sm"
                            variant="link"
                            data-test="media-modal-clear"
                            onClick={() => {
                                setSelectedIds([]);
                                setUploaded(null);
                            }}
                        >
                            Clear
                        </Button>
                    )}
                    <span className="flex-1" />
                    <Button
                        type="button"
                        variant="outline"
                        data-test="media-modal-cancel"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={selectedIds.length === 0}
                        onClick={commit}
                        data-test="media-modal-use"
                    >
                        {action}
                    </Button>
                </div>
            </DialogContent>
            <MediaDrawer
                id={editingId}
                onClose={() => setEditingId(null)}
                onChanged={changed}
                zone={zone}
            />
        </Dialog>
    );
}
