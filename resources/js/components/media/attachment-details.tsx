import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    AttachmentFields,
    AttachmentMeta,
    MEDIA_KINDS,
} from '@/components/media/attachment-fields';
import { Button } from '@/components/ui/button';
import { show as showMedia, update as updateMedia } from '@/routes/media';
import type { AttachmentField } from '@/components/media/attachment-fields';
import type { MediaDetailsData } from '@/types';

type Props = {
    id: string;
    onEdit: (id: string) => void;
    /** Raised by the window whenever the drawer over it changes a file: the details are read again. */
    version: number;
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

// Shared field markup with media-drawer.tsx's own form (attachment-fields.tsx,
// I-236); the save behaviour differs on purpose — one field at a time on
// blur here, since the modal is a quick stop, not the full editor — so each
// blur save still sends the item's current focal point, unchanged, because
// MediaItemController::update requires both (B-100: it used to default a
// missing one to 50, quietly recentring it). The window raises `version`
// whenever the drawer over it changes a file, so these details never hold, or
// save back, what the drawer replaced.
export function AttachmentDetails({ id, onEdit, version }: Props) {
    const { submit, setData, errors: fieldErrors } = useHttp<DetailsForm>();
    const [item, setItem] = useState<MediaDetailsData | null>(null);

    useEffect(() => {
        let cancelled = false;
        // Drawn again from what the server holds, so every field shows it.
        setItem(null);

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
        // `submit` is stable enough for this; the ID and the version are what matter.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id, version]);

    if (item === null) {
        return <p className="text-ink-2 p-4 text-sm">Loading…</p>;
    }

    const save = (field: AttachmentField, value: string) => {
        const payload: DetailsForm = {
            title: item.title,
            alt: item.alt ?? '',
            caption: item.caption ?? '',
            credit: item.credit ?? '',
            licence: item.licence ?? '',
            focal_x: item.focalX,
            focal_y: item.focalY,
            [field]: value,
        };
        setData(payload);
        void submit(updateMedia({ media: item.id })).then((updated) => {
            if (updated) {
                setItem(updated as MediaDetailsData);
            }
        });
    };

    return (
        <div className="grid gap-3 p-4">
            {item.previewUrl ? (
                <img
                    src={item.previewUrl}
                    alt={item.alt ?? ''}
                    className="w-full rounded"
                />
            ) : (
                <span className="bg-surface-2 text-ink-2 flex aspect-square w-full items-center justify-center rounded text-xs">
                    {MEDIA_KINDS[item.kind]}
                </span>
            )}

            <AttachmentMeta item={item} />

            <p className="text-ink-2 text-xs">
                {item.uses} place{item.uses === 1 ? '' : 's'}
            </p>

            <AttachmentFields
                item={item}
                errors={fieldErrors}
                disabled={!item.can.edit}
                onFieldBlur={save}
                onAltTextUsed={setItem}
            />

            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => onEdit(item.id)}
            >
                Edit details
            </Button>
        </div>
    );
}
