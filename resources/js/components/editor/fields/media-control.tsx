import { ImageIcon } from 'lucide-react';
import { useFieldControlContext } from '@/components/editor/fields/field-control';
import type { FieldControlProps } from '@/components/editor/fields/field-control';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useUploadTarget } from '@/components/editor/cards/card-environment';
import { useMediaPicker } from '@/components/editor/pick-media';
import { __ } from '@/lib/i18n';
import { largest, variant } from '@/lib/media-urls';
import type { CardMedia } from '@/types';

/** An image field's value: the image, its alt text for this use, and whether it is decorative. */
type ImageValue = { id: string; alt: string; decorative: boolean };

function imageOf(value: unknown, allowsDecorative: boolean): ImageValue | null {
    if (value === null || typeof value !== 'object' || !('id' in value)) {
        return null;
    }

    const { id, alt, decorative } = value as {
        id: unknown;
        alt?: unknown;
        decorative?: unknown;
    };

    return typeof id === 'string'
        ? {
              id,
              alt: typeof alt === 'string' ? alt : '',
              decorative: allowsDecorative && decorative === true,
          }
        : null;
}

/**
 * An image chosen from the library, with its alt text for this use; where
 * the field's settings allow it, a box marks this use decorative (I-237).
 * The ids of its parts start with `idPrefix`, which is `id` unless the row
 * says otherwise.
 */
export function MediaControl({
    field,
    value,
    onChange,
    id,
    idPrefix = id,
    featured: isFeatured = false,
}: FieldControlProps & {
    idPrefix?: string;
    /** The featured image's own picker: its title and "Set featured image". */
    featured?: boolean;
}) {
    const { media, loading, addMedia, noun } = useFieldControlContext();
    const uploadedFor = useUploadTarget();
    const { open, picker } = useMediaPicker();
    const choose = () =>
        open({
            caller: isFeatured ? 'featured' : 'choose',
            multi: false,
            action: isFeatured
                ? undefined
                : __('Use as :name', { name: field.label.toLowerCase() }),
            uploadedFor,
            onInsert: (ids) => {
                void addMedia(ids);
                onChange({ id: ids[0], alt: '', decorative: false });
            },
        });
    const allowsDecorative = field.settings.allowsDecorative === true;
    const featured = imageOf(value, allowsDecorative);
    const item: CardMedia | undefined = featured
        ? media[featured.id]
        : undefined;

    // Missing: nothing written for this use, and nothing in the library;
    // the hint says the same in words, so the chip is hidden from screen
    // readers. Not while the image is still on its way.
    const missing =
        featured !== null &&
        !featured.decorative &&
        item !== undefined &&
        featured.alt.trim() === '' &&
        (item.alt ?? '').trim() === '';

    return (
        <>
            {featured === null ? (
                <button
                    id={`${idPrefix}-choose`}
                    type="button"
                    onClick={choose}
                    className="border-edge text-ink-2 hover:bg-surface-3 focus-visible:ring-ring flex h-24 items-center justify-center gap-2 rounded-md border border-dashed text-[13px] outline-none focus-visible:ring-2 [&_svg]:size-4"
                >
                    <ImageIcon />
                    Choose an image
                </button>
            ) : (
                <div className="border-edge bg-surface grid gap-4 rounded-md border p-3 sm:grid-cols-[190px_minmax(0,1fr)]">
                    <figure className="m-0 grid content-start gap-1">
                        {item ? (
                            <img
                                src={
                                    variant(item.variants, 'w640') ??
                                    largest(item.variants) ??
                                    ''
                                }
                                alt=""
                                className="aspect-[3/2] w-full rounded-sm object-cover"
                            />
                        ) : loading.includes(featured.id) ? (
                            <div
                                aria-busy="true"
                                className="bg-surface-2 aspect-[3/2] w-full animate-pulse rounded-sm"
                            />
                        ) : (
                            <p className="text-ink-2 text-[13px]">
                                This image is no longer in the library.
                            </p>
                        )}
                        {item && (item.credit || item.licence) && (
                            <figcaption
                                data-test="featured-credit"
                                className="text-ink-2 text-[11px] leading-snug"
                            >
                                {[item.credit, item.licence]
                                    .filter((part) => part)
                                    .join(' · ')}
                            </figcaption>
                        )}
                    </figure>
                    <div className="grid content-start gap-2">
                        <div className="flex items-center gap-2">
                            <Label htmlFor={`${idPrefix}-alt`}>
                                Alt text for this {noun}
                            </Label>
                            {missing && (
                                <span
                                    aria-hidden
                                    data-test="alt-missing"
                                    className="bg-warn-soft text-warn-ink rounded-sm px-1.5 py-0.5 text-[11px] font-medium"
                                >
                                    Missing
                                </span>
                            )}
                        </div>
                        <input
                            id={`${idPrefix}-alt`}
                            aria-describedby={`${idPrefix}-alt-hint`}
                            disabled={featured.decorative || undefined}
                            value={featured.alt}
                            onChange={(event) =>
                                onChange({
                                    ...featured,
                                    alt: event.target.value,
                                })
                            }
                            className="border-edge bg-surface focus-visible:ring-ring h-[34px] w-full rounded-md border px-3 text-[13px] outline-none focus-visible:ring-2"
                        />
                        <p
                            id={`${idPrefix}-alt-hint`}
                            className="text-ink-2 text-xs"
                        >
                            {item?.alt
                                ? `Leave empty to use the library’s: “${item.alt}”.`
                                : 'The library has no alt text for this image, so write one here.'}
                        </p>
                        {allowsDecorative && (
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id={`${idPrefix}-decorative`}
                                    checked={featured.decorative}
                                    onCheckedChange={(checked) =>
                                        onChange({
                                            ...featured,
                                            decorative: checked === true,
                                        })
                                    }
                                />
                                <Label htmlFor={`${idPrefix}-decorative`}>
                                    Decorative: it adds nothing a reader would
                                    miss
                                </Label>
                            </div>
                        )}
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={choose}
                            >
                                Replace…
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="text-danger-ink"
                                onClick={() => onChange(null)}
                            >
                                Remove
                            </Button>
                        </div>
                    </div>
                </div>
            )}
            {picker}
        </>
    );
}
