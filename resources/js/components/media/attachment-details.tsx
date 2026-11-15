import { useHttp } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { showToast } from '@/components/flash-toast';
import {
    attachmentSize,
    MediaFields,
    mediaKind,
    savedSentence,
    useMediaSaver,
} from '@/components/media/attachment-fields';
import { __ } from '@/lib/i18n';
import { show as showMedia } from '@/routes/media';
import type { MediaDetailsData } from '@/types';

/**
 * The picker's side for the focused file (docs/architecture/design-system.md,
 * section 4.38): its picture, name, dimensions and size, and the same fields
 * as the library's detail panel, each saved as it is left. It edits the
 * file itself, so no second panel ever opens over the picker. Draw it keyed
 * by the file's ID.
 */
export function AttachmentDetails({
    id,
    onChanged,
}: {
    id: string;
    /** Hears each save, so the grid can show the fresh facts. */
    onChanged?: (details: MediaDetailsData) => void;
}) {
    const { submit } = useHttp();
    const [item, setItem] = useState<MediaDetailsData | null>(null);

    useEffect(() => {
        let cancelled = false;

        void (async () => {
            const details = (await submit(
                showMedia({ media: id }),
            )) as MediaDetailsData;

            if (!cancelled) {
                setItem(details);
            }
        })();

        return () => {
            cancelled = true;
        };
        // `submit` is stable enough for this; the ID is what matters.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id]);

    if (item === null) {
        return (
            <p className="text-small text-ink-3" aria-busy="true">
                {__('Loading…')}
            </p>
        );
    }

    return (
        <SideFields
            key={item.id}
            item={item}
            onItem={(details) => {
                setItem(details);
                onChanged?.(details);
            }}
        />
    );
}

function SideFields({
    item,
    onItem,
}: {
    item: MediaDetailsData;
    onItem: (details: MediaDetailsData) => void;
}) {
    const onSaved = useCallback(
        (fresh: MediaDetailsData, focalMoved: boolean) => {
            onItem(fresh);
            showToast({ type: 'success', message: savedSentence(focalMoved) });
        },
        [onItem],
    );
    const saver = useMediaSaver(item, onSaved);

    return (
        <div className="grid gap-4">
            {item.previewUrl ? (
                <img
                    src={item.previewUrl}
                    alt=""
                    className="outline-img-outline w-full rounded-md outline-1 -outline-offset-1"
                />
            ) : (
                <span className="bg-surface-3 text-ink-2 text-small outline-img-outline flex aspect-square w-full items-center justify-center rounded-md outline-1 -outline-offset-1">
                    {mediaKind(item.kind)}
                </span>
            )}
            <div className="grid gap-1">
                <p className="text-body font-semibold wrap-anywhere">
                    {item.title}
                </p>
                <p className="text-small text-ink-3">
                    {item.width !== null && item.height !== null
                        ? __(':width × :height · :size', {
                              width: item.width,
                              height: item.height,
                              size: attachmentSize(item.sizeBytes),
                          })
                        : attachmentSize(item.sizeBytes)}
                </p>
            </div>
            <MediaFields
                item={item}
                saver={saver}
                disabled={!item.can.edit}
                onAltTextUsed={onItem}
            />
        </div>
    );
}
