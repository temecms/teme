import { Link, useHttp } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Trash2, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { CSSProperties, KeyboardEvent, ReactNode } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { showToast } from '@/components/flash-toast';
import InputError from '@/components/input-error';
import {
    attachmentSize,
    failedSentence,
    MediaFields,
    mediaKind,
    savedSentence,
    useMediaSaver,
} from '@/components/media/attachment-fields';
import { FocalPoint } from '@/components/media/focal-point';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { Panel } from '@/components/ui/panel';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { __, __n } from '@/lib/i18n';
import { shownTitle } from '@/lib/titles';
import { uploadFile } from '@/lib/upload';
import { cn } from '@/lib/utils';
import { formatInZone } from '@/lib/zoned-time';
import {
    action as mediaAction,
    destroy as destroyMedia,
    original as mediaOriginal,
    replace as replaceMedia,
    show as showMedia,
    usages as mediaUsages,
} from '@/routes/media';
import type { MediaDetailsData, MediaUsage, UploadedFor } from '@/types';

/** The first page of where a file is used, and the cursor to more. */
type Usages = { rows: MediaUsage[]; older: string | null };

/** The panel's height, at most the view's, under the top bar (docs/architecture/design-system.md, section 4.36). */
const PANEL_STYLE = {
    '--media-panel-h': 'calc(100dvh - 92px)',
    '--facts-cols': 'max-content minmax(0, 1fr)',
} as CSSProperties;

/** “A”, “A” and “B”, or “A”, “B” and “C”. */
function quotedList(names: string[]): string {
    const quoted = names.map((name) => __('“:name”', { name }));

    return quoted.length <= 1
        ? (quoted[0] ?? '')
        : __(':list and :last', {
              list: quoted.slice(0, -1).join(', '),
              last: quoted[quoted.length - 1],
          });
}

/** Where the item a file was uploaded for stands, in Used in's words. */
function uploadedForState(item: UploadedFor): string {
    if (item.trashed) {
        return __('in the trash');
    }

    return item.status === 'published' ? __('live') : item.status;
}

/** What a person's picture becomes while the file is in the trash (I-3410): a count, never names. */
function pictureSentence(pictureOf: number): string {
    return __n(
        '1 person uses it as their picture and shows the site’s default picture until you restore it.|:count people use it as their picture and show the site’s default picture until you restore it.',
        pictureOf,
    );
}

/**
 * What Move to trash says before it asks (I-166): nothing live, or the live
 * pages the file vanishes from, named from Used in's first page, and how
 * many people use it as their picture. Whether anything live shows it comes
 * from the file's own count, which every reference moves.
 */
export function trashSentence(
    publishedUses: number,
    usages: Usages,
    pictureOf = 0,
): string {
    const live = usages.rows
        .filter((usage) => usage.published)
        .map((usage) => usage.title || __('Untitled'));
    const more = usages.older !== null;
    let sentence: string;

    if (publishedUses === 0) {
        sentence = __('Nothing live uses this file.');
    } else if (live.length === 0) {
        sentence = __(
            'It appears on live items. They show nothing in its place until you restore it.',
        );
    } else if (live.length === 1 && !more) {
        sentence = __(
            'It appears on “:title”, which is live. That item shows nothing in its place until you restore it.',
            { title: live[0] },
        );
    } else if (live.length <= 3 && !more) {
        sentence = __(
            'It appears on :count live items: :list. They show nothing in its place until you restore it.',
            { count: live.length, list: quotedList(live) },
        );
    } else {
        sentence = more
            ? __(
                  'It appears on :count or more live items, among them :list. They show nothing in its place until you restore it.',
                  { count: live.length, list: quotedList(live.slice(0, 3)) },
              )
            : __(
                  'It appears on :count live items, among them :list. They show nothing in its place until you restore it.',
                  { count: live.length, list: quotedList(live.slice(0, 3)) },
              );
    }

    return pictureOf > 0
        ? `${sentence} ${pictureSentence(pictureOf)}`
        : sentence;
}

/** What Delete permanently says before it asks: the file, how many items still refer to it, and whose picture it is. */
export function deleteSentence(
    filename: string,
    usages: Usages,
    pictureOf = 0,
): string {
    const deleted = __(
        'This deletes “:filename” and every size made from it.',
        {
            filename,
        },
    );
    const count = usages.rows.length;
    const more = usages.older !== null;
    const pictures =
        pictureOf > 0
            ? ` ${__n('1 person loses their picture.|:count people lose their picture.', pictureOf)}`
            : '';

    if (count === 0 && !more) {
        return `${deleted}${pictures} ${__('This can’t be undone.')}`;
    }

    const refer = more
        ? __(
              ':count or more items still refer to it and will show nothing in its place.',
              { count },
          )
        : __n(
              '1 item still refers to it and will show nothing in its place.|:count items still refer to it and will show nothing in its place.',
              count,
          );

    return `${deleted} ${refer}${pictures} ${__('This can’t be undone.')}`;
}

type Props = {
    id: string;
    /** Where the file stands among the rows on this page. */
    position: { current: number; total: number };
    /** Steps to the file before or after; null at either end. */
    onPrevious: (() => void) | null;
    onNext: (() => void) | null;
    onClose: () => void;
    /** Hears every change made here: the fresh details, or null once the file is deleted. */
    onChanged?: (details: MediaDetailsData | null) => void;
    /** The site's time zone, which the panel's dates are shown in. */
    zone?: string;
    /** Where "Show every file uploaded for" goes. */
    uploadedForHref?: (itemId: string) => string;
    /** Drawn inside the sheet below 1024px: not lifted off the page. */
    inSheet?: boolean;
};

/** A labelled icon button with its tooltip, which names its key. */
function HeadButton({
    label,
    tip,
    onClick,
    children,
    test,
}: {
    label: string;
    tip: string;
    onClick: (() => void) | null;
    children: ReactNode;
    test: string;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label={label}
                    data-test={test}
                    disabled={onClick === null}
                    onClick={onClick ?? undefined}
                >
                    {children}
                </Button>
            </TooltipTrigger>
            <TooltipContent>{tip}</TooltipContent>
        </Tooltip>
    );
}

/**
 * One file's details beside the library (docs/architecture/design-system.md,
 * section 4.36): where it stands with Previous and Next, its picture and
 * focal point, its facts, its fields, saved as each is left, where it is
 * used, and its actions. The destructive ones ask first, naming what they
 * touch (I-166). Draw it keyed by the file's ID.
 */
export function MediaDetailPanel({
    id,
    position,
    onPrevious,
    onNext,
    onClose,
    onChanged,
    zone = 'UTC',
    uploadedForHref,
    inSheet = false,
}: Props) {
    const { submit } = useHttp();
    const [item, setItem] = useState<MediaDetailsData | null>(null);
    const [usages, setUsages] = useState<Usages | null>(null);

    useEffect(() => {
        let cancelled = false;

        void (async () => {
            const details = (await submit(
                showMedia({ media: id }),
            )) as MediaDetailsData;

            if (cancelled) {
                return;
            }

            setItem(details);
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

    // ← and → step while focus is in the panel and not in a field; Esc closes.
    const keyDown = (event: KeyboardEvent<HTMLElement>) => {
        const target = event.target as HTMLElement;

        // A dialog drawn from here bubbles through React, not through the page.
        if (!event.currentTarget.contains(target)) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            onClose();

            return;
        }

        if (
            target.closest(
                'input, textarea, select, [contenteditable="true"], [role="application"]',
            ) !== null
        ) {
            return;
        }

        if (event.key === 'ArrowLeft' && onPrevious !== null) {
            event.preventDefault();
            onPrevious();
        } else if (event.key === 'ArrowRight' && onNext !== null) {
            event.preventDefault();
            onNext();
        }
    };

    const head = (
        <div
            className={cn(
                'flex items-center gap-1 ps-5 pt-3',
                // The sheet's own close button sits at the end.
                inSheet ? 'pe-12' : 'pe-3',
            )}
        >
            <span className="text-small text-ink-3 me-auto tabular-nums">
                {__(':current of :total', position)}
            </span>
            <HeadButton
                label={__('Previous file')}
                tip={__('Previous (←)')}
                onClick={onPrevious}
                test="media-detail-previous"
            >
                <ChevronLeft aria-hidden="true" className="rtl:-scale-x-100" />
            </HeadButton>
            <HeadButton
                label={__('Next file')}
                tip={__('Next (→)')}
                onClick={onNext}
                test="media-detail-next"
            >
                <ChevronRight aria-hidden="true" className="rtl:-scale-x-100" />
            </HeadButton>
            {!inSheet && (
                <HeadButton
                    label={__('Close the details')}
                    tip={__('Close (Esc)')}
                    onClick={onClose}
                    test="media-detail-close"
                >
                    <X aria-hidden="true" />
                </HeadButton>
            )}
        </div>
    );

    const Frame = inSheet ? 'div' : Panel;

    return (
        <Frame
            data-test="media-detail"
            className={cn(
                'flex min-h-0 flex-col',
                inSheet ? 'h-full' : 'shadow-raised rounded-lg',
            )}
            style={PANEL_STYLE}
            onKeyDown={keyDown}
        >
            {head}
            <ScrollArea
                className={cn(
                    'grid content-start gap-4 px-5 pt-2 pb-5',
                    inSheet ? 'flex-1' : 'lg:max-h-(--media-panel-h)',
                )}
            >
                {item === null ? (
                    <p className="text-small text-ink-3" aria-busy="true">
                        {__('Loading…')}
                    </p>
                ) : (
                    <DetailBody
                        key={item.id}
                        item={item}
                        usages={usages}
                        zone={zone}
                        uploadedForHref={uploadedForHref}
                        onClose={onClose}
                        onItem={(details) => {
                            setItem(details);
                            onChanged?.(details);
                        }}
                        onChanged={onChanged}
                    />
                )}
            </ScrollArea>
        </Frame>
    );
}

/** The panel's body for one file: kept apart so its fields' saver is this file's alone. */
function DetailBody({
    item,
    usages,
    zone,
    uploadedForHref,
    onClose,
    onItem,
    onChanged,
}: {
    item: MediaDetailsData;
    usages: Usages | null;
    zone: string;
    uploadedForHref?: (itemId: string) => string;
    onClose: () => void;
    /** A change to this file, shown here and told to the screen. */
    onItem: (details: MediaDetailsData) => void;
    onChanged?: (details: MediaDetailsData | null) => void;
}) {
    const { submit } = useHttp();
    const [focal, setFocal] = useState<[number, number]>([
        item.focalX,
        item.focalY,
    ]);
    const [fileError, setFileError] = useState<string | undefined>(undefined);

    // A save may finish after the panel has moved on: the screen still hears it.
    const onSaved = useCallback(
        (fresh: MediaDetailsData, focalMoved: boolean) => {
            onItem(fresh);
            showToast({ type: 'success', message: savedSentence(focalMoved) });
        },
        [onItem],
    );
    const saver = useMediaSaver(item, onSaved);

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
            showToast({ type: 'error', message: failedSentence() });

            return null;
        }
    };

    // From the panel it still shows the file; from a toast's Undo it has closed.
    const restore = async (media: string, shown: boolean) => {
        const details = await act(media, 'restore');

        if (details !== null) {
            if (shown) {
                onItem(details);
            } else {
                onChanged?.(details);
            }

            showToast({
                type: 'success',
                message: __(
                    'Restored. Every item that uses it shows it again.',
                ),
            });
        }
    };

    const trash = async () => {
        const details = await act(item.id, 'trash');

        if (details === null) {
            return;
        }

        onChanged?.(details);
        onClose();
        showToast({
            type: 'success',
            message: __('Moved “:title” to the trash.', { title: item.title }),
            action: {
                label: __('Undo'),
                onClick: () => void restore(item.id, false),
            },
        });
    };

    const destroy = async () => {
        try {
            await submit(destroyMedia({ media: item.id }));
        } catch {
            showToast({ type: 'error', message: failedSentence() });

            return;
        }

        onChanged?.(null);
        onClose();
        showToast({
            type: 'success',
            message: __('Deleted “:filename” permanently.', {
                filename: item.filename,
            }),
        });
    };

    const replace = async (file: File) => {
        const { status, body } = await uploadFile(
            replaceMedia({ media: item.id }).url,
            { file },
            () => {},
        );

        if (status === 200) {
            setFileError(undefined);
            onItem(body as unknown as MediaDetailsData);
        } else {
            setFileError(
                ((body.errors as Record<string, string[]>)?.file ?? [
                    __('The file was refused.'),
                ])[0],
            );
        }
    };

    const size =
        item.width !== null && item.height !== null
            ? __(':width × :height · :size', {
                  width: item.width,
                  height: item.height,
                  size: attachmentSize(item.sizeBytes),
              })
            : attachmentSize(item.sizeBytes);

    return (
        <>
            {item.trashed && (
                <Notice
                    tone="warn"
                    title={__('In the trash since :date.', {
                        date:
                            item.trashedAt === null
                                ? '—'
                                : formatInZone(item.trashedAt, zone),
                    })}
                >
                    {__(
                        'Items that use it show nothing in its place until you restore it.',
                    )}
                </Notice>
            )}

            {item.previewUrl && item.kind === 'image' ? (
                <FocalPoint
                    src={item.previewUrl}
                    name={item.title}
                    value={focal}
                    disabled={!item.can.edit}
                    onChange={(next) => {
                        setFocal(next);
                        saver.set('focal_x', next[0]);
                        saver.set('focal_y', next[1]);
                    }}
                    onLeave={saver.saveNow}
                />
            ) : item.kind === 'image' ? (
                <p className="text-small text-ink-2">
                    {item.processing === 'failed'
                        ? __('Teme couldn’t make this image’s sizes.')
                        : __('Preparing the preview…')}
                </p>
            ) : (
                item.fileUrl && (
                    <Button asChild variant="neutral" size="sm">
                        <a
                            href={item.fileUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="justify-self-start"
                        >
                            {__('Open the file')}
                        </a>
                    </Button>
                )
            )}

            <div className="grid gap-1">
                <h2 className="text-body font-semibold wrap-anywhere">
                    {item.title}
                </h2>
                <p className="text-small text-ink-3 wrap-anywhere">
                    {item.filename}
                </p>
            </div>

            <dl className="text-small grid grid-cols-(--facts-cols) gap-x-4 gap-y-1">
                <dt className="text-ink-3">{__('Type')}</dt>
                <dd>
                    {mediaKind(item.kind)}
                    {item.extension === ''
                        ? ''
                        : ` (${item.extension.toUpperCase()})`}
                </dd>
                <dt className="text-ink-3">{__('Size')}</dt>
                <dd>{size}</dd>
                <dt className="text-ink-3">{__('Uploaded')}</dt>
                <dd>
                    {item.uploaderName
                        ? __(':name, :date', {
                              name: item.uploaderName,
                              date: formatInZone(item.createdAt, zone),
                          })
                        : formatInZone(item.createdAt, zone)}
                </dd>
                <div data-test="media-uploaded-for" className="contents">
                    <dt className="text-ink-3">{__('Uploaded for')}</dt>
                    <dd className="grid gap-1">
                        {item.uploadedFor === null ? (
                            <span className="text-ink-2">
                                {__('Not uploaded for any item.')}
                            </span>
                        ) : (
                            <>
                                <span>
                                    {item.uploadedFor.title || __('Untitled')}{' '}
                                    <span className="text-ink-2">
                                        — {uploadedForState(item.uploadedFor)}
                                    </span>
                                </span>
                                {uploadedForHref && (
                                    <Link
                                        href={uploadedForHref(
                                            item.uploadedFor.id,
                                        )}
                                        className="text-accent-ink focus-visible:outline-ring inline-flex min-h-6 items-center justify-self-start underline underline-offset-2 outline-none focus-visible:outline-2"
                                    >
                                        {__(
                                            'Show every file uploaded for “:title”',
                                            {
                                                title:
                                                    item.uploadedFor.title ||
                                                    __('Untitled'),
                                            },
                                        )}
                                    </Link>
                                )}
                            </>
                        )}
                    </dd>
                </div>
                {item.pictureOf > 0 && (
                    <>
                        <dt className="text-ink-3">{__('Picture of')}</dt>
                        <dd data-test="media-picture-of">
                            {__n(':count person|:count people', item.pictureOf)}
                        </dd>
                    </>
                )}
            </dl>

            <fieldset disabled={!item.can.edit} className="grid gap-4">
                <MediaFields
                    item={item}
                    saver={saver}
                    disabled={!item.can.edit}
                    onAltTextUsed={onItem}
                />
            </fieldset>

            <section className="grid gap-2">
                <h3 className="text-small font-semibold">{__('Used in')}</h3>
                {usages !== null && usages.rows.length === 0 ? (
                    <p className="text-small text-ink-2">
                        {__('Not used anywhere.')}
                    </p>
                ) : (
                    <ul
                        className="text-small grid gap-1"
                        data-test="media-used-in"
                    >
                        {(usages?.rows ?? []).map((usage) => (
                            <li key={usage.id}>
                                {shownTitle(usage.title)}{' '}
                                <span className="text-ink-2">
                                    —{' '}
                                    {usage.published
                                        ? __('live')
                                        : usage.status}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section className="flex flex-wrap gap-2">
                {item.kind === 'image' && (
                    <Button asChild variant="neutral" size="sm">
                        <a href={mediaOriginal({ media: item.id }).url}>
                            {__('Download original')}
                        </a>
                    </Button>
                )}
                {item.can.edit && !item.trashed && (
                    <Button asChild variant="neutral" size="sm">
                        <label>
                            {__('Replace…')}
                            <input
                                type="file"
                                hidden
                                onChange={(event) => {
                                    const file = event.target.files?.[0];

                                    if (file) {
                                        void replace(file);
                                    }

                                    event.target.value = '';
                                }}
                            />
                        </label>
                    </Button>
                )}
                {item.can.edit && item.processing === 'failed' && (
                    <Button
                        variant="neutral"
                        size="sm"
                        onClick={() =>
                            void act(item.id, 'retry').then(
                                (details) =>
                                    details !== null && onItem(details),
                            )
                        }
                    >
                        {__('Try again')}
                    </Button>
                )}
                {item.can.delete && !item.trashed && (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            void trash();
                        }}
                    >
                        <ConfirmAction
                            confirmLabel={__('Move to trash')}
                            title={__('Move “:title” to the trash?', {
                                title: item.title,
                            })}
                            description={
                                usages === null
                                    ? ''
                                    : trashSentence(
                                          item.publishedUses,
                                          usages,
                                          item.pictureOf,
                                      )
                            }
                        >
                            <Button
                                type="submit"
                                size="sm"
                                variant="danger-quiet"
                                data-test="media-trash"
                                disabled={usages === null}
                            >
                                <Trash2 aria-hidden="true" />
                                {__('Move to trash')}
                            </Button>
                        </ConfirmAction>
                    </form>
                )}
                {item.can.delete && item.trashed && (
                    <>
                        <Button
                            type="button"
                            size="sm"
                            variant="neutral"
                            data-test="media-restore"
                            onClick={() => void restore(item.id, true)}
                        >
                            {__('Restore')}
                        </Button>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                void destroy();
                            }}
                        >
                            <ConfirmAction
                                confirmLabel={__('Delete permanently')}
                                title={__('Delete “:title” permanently?', {
                                    title: item.title,
                                })}
                                description={
                                    usages === null
                                        ? ''
                                        : deleteSentence(
                                              item.filename,
                                              usages,
                                              item.pictureOf,
                                          )
                                }
                            >
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="danger-quiet"
                                    data-test="media-delete"
                                    disabled={usages === null}
                                >
                                    {__('Delete permanently')}
                                </Button>
                            </ConfirmAction>
                        </form>
                    </>
                )}
            </section>
            <InputError message={fileError} />
        </>
    );
}
