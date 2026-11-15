import { __, __n } from '@/lib/i18n';
import { useEffect, useRef, useState } from 'react';
import { uploadFile } from '@/lib/upload';
import { store as storeMedia } from '@/routes/media';

/** One file on its way into the library. */
export type UploadEntry = {
    key: string;
    file: File;
    progress: number;
    state: 'waiting' | 'sending' | 'failed' | 'duplicate';
    message?: string;
    duplicateId?: string;
    duplicateTitle?: string;
};

/** What a batch put in the library, once the last of it finished: the new files' IDs, and how many were images. */
export type UploadedBatch = { ids: string[]; images: number };

/** Files sent at once; the rest wait their turn. */
const PARALLEL = 3;

/** The words for a batch that went in, on the screen's toast and in the modal's status. */
export function uploadedSentence({ ids, images }: UploadedBatch): string {
    if (images === 0) {
        return __n('Uploaded 1 file.|Uploaded :count files.', ids.length);
    }

    return ids.length === 1
        ? __('Uploaded 1 file. Add its alt text.')
        : __('Uploaded :count files. Add alt text to each image.', {
              count: ids.length,
          });
}

/**
 * The library's upload queue, shared by the Media screen and the media
 * modal: three files at a time, each with its progress, a failure in words
 * with Retry, and a duplicate's two choices. A file that goes in leaves the
 * list; when nothing is left sending or waiting, `onBatch` hears what the
 * batch put in, and `onExisting` hears a duplicate the person chose to use.
 * In the editor, each upload names the item open there.
 */
export function useMediaUploads({
    onBatch,
    onExisting,
    uploadedFor = null,
}: {
    onBatch: (batch: UploadedBatch) => void;
    onExisting?: (id: string) => void;
    /** The item open in the editor, which every upload names (I-553); null elsewhere. */
    uploadedFor?: string | null;
}) {
    const [entries, setEntries] = useState<UploadEntry[]>([]);
    const running = useRef(0);
    const queue = useRef<{ entry: UploadEntry; allowDuplicate: boolean }[]>([]);
    const batch = useRef<UploadedBatch>({ ids: [], images: 0 });
    const callbacks = useRef({ onBatch, onExisting });
    const target = useRef(uploadedFor);

    // The latest callbacks and item, for uploads that finish or start after a render.
    useEffect(() => {
        callbacks.current = { onBatch, onExisting };
        target.current = uploadedFor;
    }, [onBatch, onExisting, uploadedFor]);

    const update = (key: string, change: Partial<UploadEntry>) =>
        setEntries((current) =>
            current.map((entry) =>
                entry.key === key ? { ...entry, ...change } : entry,
            ),
        );

    const remove = (key: string) =>
        setEntries((current) => current.filter((entry) => entry.key !== key));

    // Nothing sending and nothing waiting: the batch is over.
    const settle = () => {
        if (running.current > 0 || queue.current.length > 0) {
            return;
        }

        const done = batch.current;
        batch.current = { ids: [], images: 0 };

        if (done.ids.length > 0) {
            callbacks.current.onBatch(done);
        }
    };

    const pump = () => {
        while (running.current < PARALLEL && queue.current.length > 0) {
            const next = queue.current.shift();

            if (next === undefined) {
                break;
            }

            const { entry, allowDuplicate } = next;
            running.current++;
            update(entry.key, {
                state: 'sending',
                progress: 0,
                message: undefined,
            });

            uploadFile(
                storeMedia().url,
                {
                    file: entry.file,
                    ...(allowDuplicate ? { allow_duplicate: '1' } : {}),
                    ...(target.current !== null
                        ? { uploaded_for: target.current }
                        : {}),
                },
                (fraction) => update(entry.key, { progress: fraction }),
            )
                .then(({ status, body }) => {
                    if (status === 201) {
                        remove(entry.key);
                        batch.current = {
                            ids: [...batch.current.ids, String(body.id)],
                            images:
                                batch.current.images +
                                (entry.file.type.startsWith('image/') ? 1 : 0),
                        };
                    } else if (status === 409) {
                        const duplicate = (body.duplicate ?? {}) as {
                            id?: string;
                            title?: string;
                        };
                        update(entry.key, {
                            state: 'duplicate',
                            duplicateId: duplicate.id,
                            duplicateTitle: duplicate.title ?? '',
                        });
                    } else {
                        const errors = (body.errors ?? {}) as Record<
                            string,
                            string[]
                        >;
                        update(entry.key, {
                            state: 'failed',
                            message:
                                errors.file?.[0] ??
                                (typeof body.message === 'string'
                                    ? body.message
                                    : __('The upload failed.')),
                        });
                    }
                })
                .catch((error: Error) =>
                    update(entry.key, {
                        state: 'failed',
                        message: error.message,
                    }),
                )
                .finally(() => {
                    running.current--;
                    pump();
                    settle();
                });
        }
    };

    const send = (entry: UploadEntry, allowDuplicate = false) => {
        queue.current.push({ entry, allowDuplicate });
        pump();
    };

    return {
        entries,
        /** How many are sending or waiting. */
        pending: entries.filter(
            (entry) => entry.state === 'waiting' || entry.state === 'sending',
        ).length,
        add: (files: FileList | null) => {
            if (files === null || files.length === 0) {
                return;
            }

            const added: UploadEntry[] = Array.from(files).map((file) => ({
                key: `${file.name}-${file.size}-${Math.random()}`,
                file,
                progress: 0,
                state: 'waiting',
            }));
            setEntries((current) => [...current, ...added]);
            added.forEach((entry) => send(entry));
        },
        retry: (entry: UploadEntry) => send(entry),
        uploadAnyway: (entry: UploadEntry) => send(entry, true),
        chooseExisting: (entry: UploadEntry) => {
            remove(entry.key);

            if (entry.duplicateId !== undefined) {
                callbacks.current.onExisting?.(entry.duplicateId);
            }
        },
    };
}
