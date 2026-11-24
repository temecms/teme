import { ImageIcon } from 'lucide-react';
import { useFieldControlContext } from '@/components/editor/fields/field-control';
import { useUploadTarget } from '@/components/editor/cards/card-environment';
import { useMediaPicker } from '@/components/editor/pick-media';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { __ } from '@/lib/i18n';
import { largest, variant } from '@/lib/media-urls';
import type { CardMedia, EditorField } from '@/types';

/** The featured image's value: the image, its alt text for this item, and whether it is decorative. */
type Featured = { id: string; alt: string; decorative: boolean };

function featuredOf(
    value: unknown,
    allowsDecorative: boolean,
): Featured | null {
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

/** An outside picture's address: a featured image an import left on the old site while it copies the file. */
function outsideOf(value: unknown): string | null {
    if (value === null || typeof value !== 'object' || !('source' in value)) {
        return null;
    }

    const { source } = value as { source: unknown };

    return typeof source === 'string' && source !== '' ? source : null;
}

/**
 * The image that stands for the article in lists, feeds and social cards,
 * as a compact strip in the writing column (docs/architecture/design-system.md,
 * 4.39): its thumbnail, its name, "Featured image · 2400 × 1600 · alt text
 * set", then Replace, Edit and Remove. Edit holds its alt text for this item
 * and, where the field allows it, Decorative (I-237). With none chosen, one
 * full-width button adds it. An outside picture, which an import left on
 * the old site while it copies the file, says so, with Replace and Remove.
 */
export function FeaturedImage({
    field,
    value,
    onChange,
}: {
    field: EditorField;
    value: unknown;
    onChange: (value: unknown) => void;
}) {
    const { media, loading, addMedia, noun } = useFieldControlContext();
    const uploadedFor = useUploadTarget();
    const { open, picker } = useMediaPicker();
    const allowsDecorative = field.settings.allowsDecorative === true;
    const featured = featuredOf(value, allowsDecorative);
    const item: CardMedia | undefined =
        featured === null ? undefined : media[featured.id];
    const choose = () =>
        open({
            caller: 'featured',
            multi: false,
            uploadedFor,
            onInsert: (ids) => {
                void addMedia(ids);
                onChange({ id: ids[0], alt: '', decorative: false });
            },
        });

    const outside = outsideOf(value);

    if (featured === null && outside !== null) {
        return (
            <section
                aria-labelledby="editor-featured-label"
                data-test="featured-strip"
                className="bg-surface-2 flex flex-wrap items-center gap-4 rounded-lg p-3"
            >
                <img
                    src={outside}
                    alt=""
                    referrerPolicy="no-referrer"
                    className="h-16 w-24 shrink-0 rounded-md object-cover"
                />
                <div className="min-w-0 flex-1">
                    <p
                        data-test="featured-outside"
                        className="text-body truncate font-medium"
                    >
                        {__('From the old site, being copied')}
                    </p>
                    <p
                        id="editor-featured-label"
                        className="text-small text-ink-3"
                    >
                        {__('Featured image')}
                    </p>
                </div>
                <div className="flex shrink-0 gap-1">
                    <Button
                        id="editor-featured-choose"
                        type="button"
                        variant="neutral"
                        size="sm"
                        onClick={choose}
                    >
                        {__('Replace')}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => onChange(null)}
                    >
                        {__('Remove')}
                    </Button>
                </div>
                {picker}
            </section>
        );
    }

    if (featured === null) {
        return (
            <>
                <Button
                    id="editor-featured-choose"
                    type="button"
                    variant="neutral"
                    onClick={choose}
                    className="text-ink-2 bg-surface-2 hover:bg-surface-3 h-auto w-full justify-start gap-3 rounded-lg p-4"
                >
                    <ImageIcon />
                    {__('Add a featured image')}
                </Button>
                {picker}
            </>
        );
    }

    // Nothing written for this item and nothing in the library, once the image is here.
    const missing =
        !featured.decorative &&
        item !== undefined &&
        featured.alt.trim() === '' &&
        (item.alt ?? '').trim() === '';
    const thumb =
        item === undefined
            ? null
            : (variant(item.variants, 'w640') ?? largest(item.variants));

    return (
        <section
            aria-labelledby="editor-featured-label"
            data-test="featured-strip"
            className="bg-surface-2 flex flex-wrap items-center gap-4 rounded-lg p-3"
        >
            {thumb !== null ? (
                <img
                    src={thumb}
                    alt=""
                    className="h-16 w-24 shrink-0 rounded-md object-cover"
                />
            ) : (
                <span
                    aria-busy={loading.includes(featured.id) || undefined}
                    className="bg-surface-3 h-16 w-24 shrink-0 rounded-md"
                />
            )}
            <div className="min-w-0 flex-1">
                <p className="text-body truncate font-medium">
                    {item?.title ??
                        (loading.includes(featured.id)
                            ? __('Loading…')
                            : __('This image is no longer in the library.'))}
                </p>
                <p id="editor-featured-label" className="text-small text-ink-3">
                    {__('Featured image')}
                    {item !== undefined && (
                        <>
                            {' · '}
                            {item.width} × {item.height}
                            {' · '}
                            {missing ? (
                                <span
                                    data-test="alt-missing"
                                    className="text-warn-ink"
                                >
                                    {__('no alt text')}
                                </span>
                            ) : featured.decorative ? (
                                __('decorative')
                            ) : (
                                __('alt text set')
                            )}
                        </>
                    )}
                </p>
            </div>
            <div className="flex shrink-0 gap-1">
                <Button
                    id="editor-featured-choose"
                    type="button"
                    variant="neutral"
                    size="sm"
                    onClick={choose}
                >
                    {__('Replace')}
                </Button>
                <Popover>
                    <PopoverTrigger asChild>
                        <Button
                            id="editor-featured-edit"
                            type="button"
                            variant="ghost"
                            size="sm"
                        >
                            {__('Edit')}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent align="end" className="grid w-90 gap-2">
                        <Label htmlFor="editor-featured-alt">
                            {__('Alt text for this :noun', { noun })}
                        </Label>
                        <Input
                            id="editor-featured-alt"
                            aria-describedby="editor-featured-alt-hint"
                            disabled={featured.decorative || undefined}
                            value={featured.alt}
                            onChange={(event) =>
                                onChange({
                                    ...featured,
                                    alt: event.target.value,
                                })
                            }
                            className="h-8"
                        />
                        <p
                            id="editor-featured-alt-hint"
                            className="text-ink-2 text-caption"
                        >
                            {item?.alt
                                ? __(
                                      'Leave empty to use the library’s: “:alt”.',
                                      { alt: item.alt },
                                  )
                                : __(
                                      'The library has no alt text for this image, so write one here.',
                                  )}
                        </p>
                        {allowsDecorative && (
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="editor-featured-decorative"
                                    checked={featured.decorative}
                                    onCheckedChange={(checked) =>
                                        onChange({
                                            ...featured,
                                            decorative: checked === true,
                                        })
                                    }
                                />
                                <Label htmlFor="editor-featured-decorative">
                                    {__(
                                        'Decorative: it adds nothing a reader would miss',
                                    )}
                                </Label>
                            </div>
                        )}
                        {item !== undefined &&
                            (item.credit || item.licence) && (
                                <p
                                    data-test="featured-credit"
                                    className="text-ink-3 text-caption"
                                >
                                    {[item.credit, item.licence]
                                        .filter((part) => part)
                                        .join(' · ')}
                                </p>
                            )}
                    </PopoverContent>
                </Popover>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => onChange(null)}
                >
                    {__('Remove')}
                </Button>
            </div>
            {picker}
        </section>
    );
}
