import { useState } from 'react';
import { MediaModal } from '@/components/media/media-modal';
import { Button } from '@/components/ui/button';
import { largest, variant } from '@/lib/media-urls';
import type { SearchPreview } from '@/components/editor/use-search-preview';
import type { CardMedia, EditorField } from '@/types/editor';

/** The social image field's value: the image, as the featured image's is stored. */
type SocialValue = { id: string; alt: string; decorative: false };

/** The chosen image's ID, or null. */
function chosenId(value: unknown): string | null {
    const id = (value as { id?: unknown } | null | undefined)?.id;

    return typeof id === 'string' && id !== '' ? id : null;
}

/** What the card uses while no social image is chosen. */
const FALLBACK: Record<string, string> = {
    featured: 'Uses the featured image.',
    body: 'Uses the first image in the body.',
    default: 'Uses the site’s default social image.',
};

/**
 * The image on the card when the item is shared (I-293), chosen from the
 * library; with none chosen, it says which image the card falls back to.
 */
export function SocialImage({
    field,
    value,
    onChange,
    card,
    media,
    loading,
    addMedia,
}: {
    field: EditorField;
    value: unknown;
    onChange: (value: SocialValue | null) => void;
    card: SearchPreview['card'];
    media: Record<string, CardMedia>;
    loading: string[];
    addMedia: (ids: string[]) => Promise<void>;
}) {
    const [choosing, setChoosing] = useState(false);
    const id = chosenId(value);
    const item = id === null ? undefined : media[id];

    return (
        <div className="grid gap-2">
            <span className="text-sm leading-none font-medium">
                {field.label}
            </span>
            {id === null ? (
                <div className="flex flex-wrap items-center gap-2">
                    <p className="text-muted-foreground text-xs">
                        {FALLBACK[card.imageFrom ?? ''] ?? 'No image.'}
                    </p>
                    <Button
                        id="editor-social-image"
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setChoosing(true)}
                    >
                        Choose an image
                    </Button>
                </div>
            ) : (
                <div className="flex flex-wrap items-center gap-3">
                    {item ? (
                        <img
                            src={
                                variant(item.variants, 'w320') ??
                                largest(item.variants) ??
                                ''
                            }
                            alt=""
                            className="aspect-[40/21] w-24 rounded-sm object-cover"
                        />
                    ) : loading.includes(id) ? (
                        <div
                            aria-busy="true"
                            className="bg-muted aspect-[40/21] w-24 animate-pulse rounded-sm"
                        />
                    ) : (
                        <p className="text-muted-foreground text-xs">
                            This image is no longer in the library.
                        </p>
                    )}
                    <div className="flex gap-2">
                        {item !== undefined && (
                            <Button
                                id="editor-social-image"
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setChoosing(true)}
                            >
                                Replace
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => onChange(null)}
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
                action="Use as social image"
                onChoose={(ids) => {
                    void addMedia(ids);
                    onChange({ id: ids[0], alt: '', decorative: false });
                }}
            />
        </div>
    );
}
