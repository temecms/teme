import type { CSSProperties } from 'react';
import { ImageIcon } from 'lucide-react';
import { useState } from 'react';
import { useCardMedia } from '@/components/editor/use-card-media';
import { MediaModal } from '@/components/media/media-modal';
import { Button } from '@/components/ui/button';
import { largest, variant } from '@/lib/media-urls';
import type { CardMedia, FieldDefinition } from '@/types';

/** The chosen image's two columns from 640px: the picture, then its words. */
const MEDIA_COLUMNS = {
    '--media-cols': '160px minmax(0, 1fr)',
} as CSSProperties;

type Props = {
    field: FieldDefinition;
    name: string;
    value: unknown;
    /** The chosen image as the server sees it, or null when none or gone. */
    media?: CardMedia | null;
    disabled?: boolean;
};

/**
 * A media setting's control: one image from the library, chosen as a
 * featured image is (I-237) and sent as its ID in a hidden input, empty when
 * none is chosen (I-418).
 */
export function MediaSetting({ field, name, value, media, disabled }: Props) {
    const id = `field-${field.key}`;
    const [choosing, setChoosing] = useState(false);
    const [chosen, setChosen] = useState(
        typeof value === 'string' ? value : '',
    );
    const initial: Record<string, CardMedia> =
        media && typeof value === 'string' && value !== ''
            ? { [value]: media }
            : {};
    const { media: known, loading, addMedia } = useCardMedia(initial);
    const item: CardMedia | undefined =
        chosen !== '' ? known[chosen] : undefined;

    return (
        <div
            id={id}
            aria-describedby={`${id}-hint ${id}-error`}
            className="grid gap-2"
        >
            <input
                type="hidden"
                name={name}
                value={chosen}
                disabled={disabled}
            />
            {chosen === '' ? (
                <button
                    type="button"
                    data-test="media-choose"
                    disabled={disabled}
                    onClick={() => setChoosing(true)}
                    className="border-edge text-ink-2 hover:bg-surface-3 focus-visible:ring-ring text-small flex h-24 items-center justify-center gap-2 rounded-md border border-dashed outline-none focus-visible:ring-2 [&_svg]:size-4"
                >
                    <ImageIcon />
                    Choose an image
                </button>
            ) : (
                <div
                    className="border-edge bg-surface grid gap-3 rounded-md border p-3 sm:grid-cols-(--media-cols)"
                    style={MEDIA_COLUMNS}
                >
                    {item ? (
                        <img
                            data-test="media-preview"
                            src={
                                variant(item.variants, 'w640') ??
                                largest(item.variants) ??
                                ''
                            }
                            alt=""
                            className="aspect-[3/2] w-full rounded-sm object-cover"
                        />
                    ) : loading.includes(chosen) ? (
                        <div
                            aria-busy="true"
                            className="bg-surface-2 aspect-[3/2] w-full animate-pulse rounded-sm"
                        />
                    ) : (
                        <p
                            data-test="media-gone"
                            className="text-ink-2 text-small"
                        >
                            This image is no longer in the library.
                        </p>
                    )}
                    <div className="flex content-start gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-test="media-replace"
                            disabled={disabled}
                            onClick={() => setChoosing(true)}
                        >
                            Replace
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            data-test="media-remove"
                            disabled={disabled}
                            onClick={() => setChosen('')}
                        >
                            Remove
                        </Button>
                    </div>
                </div>
            )}
            <MediaModal
                open={choosing}
                onOpenChange={setChoosing}
                kinds={['image']}
                action={`Use as ${field.label.toLowerCase()}`}
                onChoose={(ids) => {
                    void addMedia(ids);
                    setChosen(ids[0]);
                }}
            />
        </div>
    );
}
