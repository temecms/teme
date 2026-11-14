import { Link, useHttp } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { showToast } from '@/components/flash-toast';
import InputError from '@/components/input-error';
import {
    AttachmentFields,
    AttachmentMeta,
} from '@/components/media/attachment-fields';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { uploadFile } from '@/lib/upload';
import { formatInZone } from '@/lib/zoned-time';
import {
    action as mediaAction,
    destroy as destroyMedia,
    original as mediaOriginal,
    replace as replaceMedia,
    show as showMedia,
    update as updateMedia,
    usages as mediaUsages,
} from '@/routes/media';
import type { MediaDetailsData, MediaUsage, UploadedFor } from '@/types';
import { shownTitle } from '@/lib/titles';

type Props = {
    id: string | null;
    onClose: () => void;
    /** Hears every change made here: the fresh details, or null once the file is deleted. */
    onChanged?: (details: MediaDetailsData | null) => void;
    /** The site's time zone, which the drawer's dates are shown in. */
    zone?: string;
    /** Where "Show every file uploaded for" goes: only the Media screen gives one. */
    uploadedForHref?: (itemId: string) => string;
};

type DetailsForm = {
    title: string;
    alt: string;
    caption: string;
    credit: string;
    licence: string;
    focal_x: number;
    focal_y: number;
};

/** The first page of where a file is used, and the cursor to more. */
type Usages = { rows: MediaUsage[]; older: string | null };

const altHint =
    'What the image shows, for people who can’t see it. Used wherever you place the image without alt text of its own. You choose whether it’s decorative where you place it.';

/** Said when a request is refused for anything but a field's error. */
const FAILED = 'That didn’t work, so nothing changed. Try again.';

const RESTORED = 'Restored. Every item that uses it shows it again.';

const UNTIL_RESTORED = 'nothing in its place until you restore it.';

/** “A”, “A” and “B”, or “A”, “B” and “C”. */
function quotedList(names: string[]): string {
    const quoted = names.map((name) => `“${name}”`);

    return quoted.length <= 1
        ? (quoted[0] ?? '')
        : `${quoted.slice(0, -1).join(', ')} and ${quoted[quoted.length - 1]}`;
}

/** Where the item a file was uploaded for stands, in Used in's words. */
function uploadedForState(item: UploadedFor): string {
    if (item.trashed) {
        return 'in the trash';
    }

    return item.status === 'published' ? 'live' : item.status;
}

/**
 * What Move to trash says before it asks (I-166): nothing live, or the live
 * pages the file vanishes from, named from Used in's first page. Whether
 * anything live shows it comes from the file's own count, which every
 * reference moves.
 */
export function trashSentence(publishedUses: number, usages: Usages): string {
    if (publishedUses === 0) {
        return 'Nothing live uses this file.';
    }

    const live = usages.rows
        .filter((usage) => usage.published)
        .map((usage) => usage.title || 'Untitled');
    const more = usages.older !== null;

    if (live.length === 0) {
        return `It appears on live items. They show ${UNTIL_RESTORED}`;
    }

    if (live.length === 1 && !more) {
        return `It appears on “${live[0]}”, which is live. That item shows ${UNTIL_RESTORED}`;
    }

    if (live.length <= 3 && !more) {
        return `It appears on ${live.length} live items: ${quotedList(live)}. They show ${UNTIL_RESTORED}`;
    }

    return `It appears on ${live.length}${more ? ' or more' : ''} live items, among them ${quotedList(live.slice(0, 3))}. They show ${UNTIL_RESTORED}`;
}

/** What Delete permanently says before it asks: the file, and how many items still refer to it. */
export function deleteSentence(filename: string, usages: Usages): string {
    const deleted = `This deletes “${filename}” and every size made from it.`;
    const count = usages.rows.length;
    const more = usages.older !== null;

    if (count === 0 && !more) {
        return `${deleted} This can’t be undone.`;
    }

    const refer =
        count === 1 && !more
            ? '1 item still refers'
            : `${count}${more ? ' or more' : ''} items still refer`;

    return `${deleted} ${refer} to it and will show nothing in its place. This can’t be undone.`;
}

/**
 * One file's drawer, from the Media screen or over the media modal: its
 * preview and focal point, its facts, its fields, what it was uploaded for,
 * where it is used and its
 * tools; and in the footer, apart, Move to trash (or Restore and Delete
 * permanently) and Save. The destructive actions ask first, naming what
 * they touch (I-166).
 */
export function MediaDrawer({
    id,
    onClose,
    onChanged,
    zone = 'UTC',
    uploadedForHref,
}: Props) {
    // useHttp's submit() resolves undefined on a 422 (errors land in its own
    // `errors` state) rather than rejecting; other failures still throw.
    const { submit, setData, errors: fieldErrors } = useHttp<DetailsForm>();
    const formId = useId();
    const [item, setItem] = useState<MediaDetailsData | null>(null);
    const [usages, setUsages] = useState<Usages | null>(null);
    const [focal, setFocal] = useState<[number, number]>([50, 50]);
    const [fileError, setFileError] = useState<string | undefined>(undefined);

    const accept = (details: MediaDetailsData) => {
        setItem(details);
        setFocal([details.focalX, details.focalY]);
        setFileError(undefined);
    };

    // A change made here: shown, and told to whoever opened the drawer.
    const changed = (details: MediaDetailsData) => {
        accept(details);
        onChanged?.(details);
    };

    useEffect(() => {
        let cancelled = false;
        setItem(null);
        setUsages(null);

        if (id === null) {
            return;
        }

        void (async () => {
            const details = (await submit(
                showMedia({ media: id }),
            )) as MediaDetailsData;

            if (cancelled) {
                return;
            }

            accept(details);
            const found = (await submit(mediaUsages({ media: id }))) as Usages;

            if (!cancelled) {
                setUsages(found);
            }
        })();

        return () => {
            cancelled = true;
        };
        // `submit` is stable enough for this; the ID is what matters.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id]);

    const act = async (
        media: string,
        action: 'trash' | 'restore' | 'retry',
    ): Promise<MediaDetailsData | null> => {
        try {
            return (
                ((await submit(mediaAction({ media, action }))) as
                    | MediaDetailsData
                    | undefined) ?? null
            );
        } catch {
            showToast({ type: 'error', message: FAILED });

            return null;
        }
    };

    // From the footer the drawer still shows the file; from a toast's Undo it has closed.
    const restore = async (media: string) => {
        const details = await act(media, 'restore');

        if (details !== null) {
            setItem((current) =>
                current !== null && current.id === details.id
                    ? details
                    : current,
            );
            onChanged?.(details);
            showToast({ type: 'success', message: RESTORED });
        }
    };

    const trash = async (open: MediaDetailsData) => {
        const details = await act(open.id, 'trash');

        if (details === null) {
            return;
        }

        onChanged?.(details);
        onClose();
        showToast({
            type: 'success',
            message: `Moved “${open.title}” to the trash.`,
            action: { label: 'Undo', onClick: () => void restore(open.id) },
        });
    };

    const destroy = async (open: MediaDetailsData) => {
        try {
            await submit(destroyMedia({ media: open.id }));
        } catch {
            showToast({ type: 'error', message: FAILED });

            return;
        }

        onChanged?.(null);
        onClose();
        showToast({
            type: 'success',
            message: `Deleted “${open.filename}” permanently.`,
        });
    };

    const save = async (form: HTMLFormElement, open: MediaDetailsData) => {
        const data = new FormData(form);
        const field = (name: string) => (data.get(name) as string | null) ?? '';
        const moved = focal[0] !== open.focalX || focal[1] !== open.focalY;
        setData({
            title: field('title'),
            alt: field('alt'),
            caption: field('caption'),
            credit: field('credit'),
            licence: field('licence'),
            focal_x: focal[0],
            focal_y: focal[1],
        });

        try {
            const saved = (await submit(updateMedia({ media: open.id }))) as
                | MediaDetailsData
                | undefined;

            // Undefined on a field's error, which shows under its field.
            if (saved === undefined) {
                return;
            }

            onChanged?.(saved);
            onClose();
            showToast({
                type: 'success',
                message: moved
                    ? 'Saved. Its sizes are being made again around the new focal point.'
                    : 'Saved.',
            });
        } catch {
            showToast({ type: 'error', message: FAILED });
        }
    };

    if (item === null) {
        return (
            <Sheet
                open={id !== null}
                onOpenChange={(open) => !open && onClose()}
            />
        );
    }

    return (
        <Sheet open={id !== null} onOpenChange={(open) => !open && onClose()}>
            <SheetContent className="flex w-full flex-col gap-0 p-0 sm:max-w-xl">
                <SheetHeader className="border-b">
                    <SheetTitle>{item.title}</SheetTitle>
                </SheetHeader>

                <div className="grid flex-1 content-start gap-4 overflow-y-auto p-4">
                    {item.trashed && (
                        <div
                            role="note"
                            className="bg-surface-2 grid gap-1 rounded-md border p-3 text-[13px]"
                        >
                            <p className="font-medium">
                                In the trash since{' '}
                                {item.trashedAt === null
                                    ? '—'
                                    : formatInZone(item.trashedAt, zone)}
                                .
                            </p>
                            <p className="text-ink-2">
                                Items that use it show nothing in its place
                                until you restore it.
                            </p>
                        </div>
                    )}

                    {item.previewUrl ? (
                        <button
                            type="button"
                            className="relative block w-full"
                            disabled={!item.can.edit}
                            aria-label="Set the focal point"
                            onClick={(event) => {
                                const box =
                                    event.currentTarget.getBoundingClientRect();
                                setFocal([
                                    Math.round(
                                        ((event.clientX - box.left) /
                                            box.width) *
                                            100,
                                    ),
                                    Math.round(
                                        ((event.clientY - box.top) /
                                            box.height) *
                                            100,
                                    ),
                                ]);
                            }}
                        >
                            <img
                                src={item.previewUrl}
                                alt={item.alt ?? ''}
                                className="w-full rounded"
                            />
                            <span
                                className="border-on-photo bg-photo-scrim absolute size-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2"
                                style={{
                                    left: `${focal[0]}%`,
                                    top: `${focal[1]}%`,
                                }}
                            />
                        </button>
                    ) : item.kind === 'image' ? (
                        <p className="text-ink-2 text-sm">
                            {item.processing === 'failed'
                                ? 'Teme couldn’t make this image’s sizes.'
                                : 'Preparing the preview…'}
                        </p>
                    ) : (
                        item.fileUrl && (
                            <a
                                href={item.fileUrl}
                                target="_blank"
                                rel="noreferrer"
                                className="text-sm underline"
                            >
                                Open the file
                            </a>
                        )
                    )}

                    <AttachmentMeta item={item} />

                    <form
                        id={formId}
                        className="grid gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void save(event.currentTarget, item);
                        }}
                    >
                        <fieldset
                            disabled={!item.can.edit}
                            className="grid gap-3"
                        >
                            <AttachmentFields
                                item={item}
                                errors={fieldErrors}
                                altHint={altHint}
                                onAltTextUsed={(details) => {
                                    // Not accept(): an unsaved focal point stays where it was clicked.
                                    setItem(details);
                                    onChanged?.(details);
                                }}
                            />
                            {item.kind === 'image' && (
                                <p className="text-ink-2 text-xs">
                                    Click the preview to set the focal point,
                                    which square crops keep in view.
                                </p>
                            )}
                        </fieldset>
                    </form>

                    <section
                        data-test="media-uploaded-for"
                        className="grid gap-2"
                    >
                        <h3 className="text-sm font-medium">Uploaded for</h3>
                        {item.uploadedFor === null ? (
                            <p className="text-ink-2 text-sm">
                                Not uploaded for any item.
                            </p>
                        ) : (
                            <>
                                <p className="text-sm">
                                    {item.uploadedFor.title || 'Untitled'}{' '}
                                    <span className="text-ink-2">
                                        — {uploadedForState(item.uploadedFor)}
                                    </span>
                                </p>
                                {uploadedForHref && (
                                    <Link
                                        href={uploadedForHref(
                                            item.uploadedFor.id,
                                        )}
                                        className="inline-flex min-h-6 items-center justify-self-start text-sm underline underline-offset-2"
                                    >
                                        {`Show every file uploaded for “${item.uploadedFor.title || 'Untitled'}”`}
                                    </Link>
                                )}
                            </>
                        )}
                    </section>

                    <section className="grid gap-2">
                        <h3 className="text-sm font-medium">Used in</h3>
                        {usages !== null && usages.rows.length === 0 ? (
                            <p className="text-ink-2 text-sm">
                                Not used anywhere.
                            </p>
                        ) : (
                            <ul className="text-sm">
                                {(usages?.rows ?? []).map((usage) => (
                                    <li key={usage.id}>
                                        {shownTitle(usage.title)}{' '}
                                        <span className="text-ink-2">
                                            —{' '}
                                            {usage.published
                                                ? 'live'
                                                : usage.status}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="flex flex-wrap gap-2">
                        {item.kind === 'image' && (
                            <Button asChild variant="outline" size="sm">
                                <a href={mediaOriginal({ media: item.id }).url}>
                                    Download original
                                </a>
                            </Button>
                        )}
                        {item.can.edit && !item.trashed && (
                            <Button asChild variant="outline" size="sm">
                                <label>
                                    Replace…
                                    <input
                                        type="file"
                                        hidden
                                        onChange={async (event) => {
                                            const file =
                                                event.target.files?.[0];

                                            if (file) {
                                                const { status, body } =
                                                    await uploadFile(
                                                        replaceMedia({
                                                            media: item.id,
                                                        }).url,
                                                        { file },
                                                        () => {},
                                                    );

                                                if (status === 200) {
                                                    changed(
                                                        body as unknown as MediaDetailsData,
                                                    );
                                                } else {
                                                    setFileError(
                                                        ((
                                                            body.errors as Record<
                                                                string,
                                                                string[]
                                                            >
                                                        )?.file ?? [
                                                            'The file was refused.',
                                                        ])[0],
                                                    );
                                                }
                                            }
                                        }}
                                    />
                                </label>
                            </Button>
                        )}
                        {item.can.edit && item.processing === 'failed' && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    void act(item.id, 'retry').then(
                                        (details) =>
                                            details !== null &&
                                            changed(details),
                                    )
                                }
                            >
                                Try again
                            </Button>
                        )}
                    </section>
                    <InputError message={fileError} />
                </div>

                <SheetFooter
                    data-test="media-drawer-footer"
                    className="flex-row flex-wrap items-center justify-between gap-2 border-t"
                >
                    <div className="flex flex-wrap gap-2">
                        {item.can.delete && !item.trashed && (
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    void trash(item);
                                }}
                            >
                                <ConfirmAction
                                    confirmLabel="Move to trash"
                                    title={`Move “${item.title}” to the trash?`}
                                    description={
                                        usages === null
                                            ? ''
                                            : trashSentence(
                                                  item.publishedUses,
                                                  usages,
                                              )
                                    }
                                >
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="destructive"
                                        data-test="media-trash"
                                        disabled={usages === null}
                                    >
                                        <Trash2 aria-hidden="true" />
                                        Move to trash
                                    </Button>
                                </ConfirmAction>
                            </form>
                        )}
                        {item.can.delete && item.trashed && (
                            <>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    data-test="media-restore"
                                    onClick={() => void restore(item.id)}
                                >
                                    Restore
                                </Button>
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        void destroy(item);
                                    }}
                                >
                                    <ConfirmAction
                                        confirmLabel="Delete permanently"
                                        title={`Delete “${item.title}” permanently?`}
                                        description={
                                            usages === null
                                                ? ''
                                                : deleteSentence(
                                                      item.filename,
                                                      usages,
                                                  )
                                        }
                                    >
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="destructive"
                                            data-test="media-delete"
                                            disabled={usages === null}
                                        >
                                            Delete permanently
                                        </Button>
                                    </ConfirmAction>
                                </form>
                            </>
                        )}
                    </div>
                    {item.can.edit && (
                        <Button
                            type="submit"
                            form={formId}
                            data-test="media-save"
                        >
                            Save
                        </Button>
                    )}
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
