import type { ReactNodeViewProps } from '@tiptap/react';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import { largest, variant } from '@/lib/media-urls';
import { cn } from '@/lib/utils';
import { useMediaPicker } from '../pick-media';
import type { MediaPickerOptions } from '../pick-media';
import { CardField, CardFrame, TextSetting } from './card-frame';
import { isMissingAlt } from '../rail/checks';
import {
    optionsFor,
    useCardEnvironment,
    useUploadTarget,
} from './card-environment';
import type { CardSettingsProps } from './card-environment';

type ImageAttrs = {
    mediaId: string | null;
    alt: string | null;
    decorative: boolean | null;
    caption: string | null;
    credit: string | null;
    width: string | null;
};

const WIDTHS: Record<string, string> = {
    column: 'max-w-full',
    wide: 'max-w-full -mx-2',
    full: 'max-w-full -mx-3',
};

/**
 * An image card as it will look: the image at its stored width, the caption
 * and credit, and an Alt missing flag. A new card opens the library at once,
 * and closing it without choosing removes the card, so no card is left
 * naming no image.
 */
export function ImageCard({
    node,
    selected,
    updateAttributes,
    deleteNode,
}: ReactNodeViewProps) {
    const { media, loading, addMedia } = useCardEnvironment();
    const attrs = node.attrs as ImageAttrs;
    const item = attrs.mediaId ? media[attrs.mediaId] : undefined;
    const uploadedFor = useUploadTarget();
    const pick = (caller: 'insert' | 'replace'): MediaPickerOptions => ({
        caller,
        multi: false,
        uploadedFor,
        onInsert: (ids) => {
            void addMedia(ids);
            updateAttributes({ mediaId: ids[0] });
        },
        // A new card closed without a choice is removed, so none names no image.
        onClose: caller === 'insert' ? deleteNode : undefined,
    });
    const { open, picker } = useMediaPicker(
        attrs.mediaId ? null : pick('insert'),
    );
    const missingAlt = item !== undefined && isMissingAlt(attrs, item);

    return (
        <>
            <CardFrame
                label="Image"
                selected={selected}
                warning={missingAlt ? { label: 'Alt missing' } : null}
                onReplace={() =>
                    open(pick(attrs.mediaId ? 'replace' : 'insert'))
                }
                replaceLabel="Replace image"
                onRemove={deleteNode}
                settings={
                    <ImageSettings
                        attrs={node.attrs}
                        update={updateAttributes}
                    />
                }
            >
                {item ? (
                    <figure
                        className={cn(
                            'relative',
                            WIDTHS[attrs.width ?? 'column'],
                        )}
                    >
                        <img
                            src={
                                variant(item.variants, 'w1280') ??
                                largest(item.variants) ??
                                ''
                            }
                            alt={
                                attrs.decorative
                                    ? ''
                                    : (attrs.alt ?? '').trim() ||
                                      (item.alt ?? '')
                            }
                            width={item.width}
                            height={item.height}
                            className="h-auto w-full rounded-sm"
                        />
                        {missingAlt && (
                            <span className="bg-warn-soft text-warn-ink absolute top-2 left-2 rounded px-1.5 py-0.5 text-[11px] font-medium">
                                Alt missing
                            </span>
                        )}
                        {(attrs.caption || attrs.credit) && (
                            <figcaption className="text-ink-2 mt-1.5 text-[13px]">
                                {attrs.caption}
                                {attrs.credit && (
                                    <span className="ml-2 text-[11px] uppercase">
                                        {attrs.credit}
                                    </span>
                                )}
                            </figcaption>
                        )}
                    </figure>
                ) : attrs.mediaId && loading.includes(attrs.mediaId) ? (
                    <div
                        aria-busy="true"
                        className="bg-surface-2 aspect-[3/2] w-full animate-pulse rounded-sm"
                    />
                ) : (
                    <p className="text-ink-2 text-[13px]">
                        {attrs.mediaId
                            ? 'This image is no longer in the library, so the site shows nothing here. Replace it or remove the card.'
                            : 'Choose an image from the library.'}
                    </p>
                )}
            </CardFrame>
            {picker}
        </>
    );
}

/** An image card's settings, in the card's panel or the rail's inspector. */
export function ImageSettings({
    attrs: raw,
    update: updateAttributes,
}: CardSettingsProps) {
    const { media, manifest } = useCardEnvironment();
    const item =
        typeof raw.mediaId === 'string' ? media[raw.mediaId] : undefined;
    const attrs = raw as ImageAttrs;

    return (
        <>
            <TextSetting
                label="Alt text"
                hint={
                    item?.alt
                        ? `Leave empty to use the library's: “${item.alt}”.`
                        : 'What the image shows, for someone who cannot see it.'
                }
                value={attrs.alt}
                onChange={(alt) => updateAttributes({ alt })}
                multiline
            />
            <div className="flex items-center gap-2">
                <Checkbox
                    id={`${String(raw.id)}-decorative`}
                    checked={attrs.decorative === true}
                    onCheckedChange={(checked) =>
                        updateAttributes({
                            decorative: checked === true,
                        })
                    }
                />
                <Label htmlFor={`${String(raw.id)}-decorative`}>
                    Decorative: it adds nothing a reader would miss
                </Label>
            </div>
            <TextSetting
                label="Caption"
                value={attrs.caption}
                onChange={(caption) => updateAttributes({ caption })}
            />
            <TextSetting
                label="Credit"
                value={attrs.credit}
                onChange={(credit) => updateAttributes({ credit })}
            />
            <CardField label="Width">
                {(props) => (
                    <SelectField
                        {...props}
                        value={attrs.width ?? 'column'}
                        onValueChange={(width) => updateAttributes({ width })}
                        options={optionsFor(manifest, 'image', 'width')}
                        className="h-[34px] w-full max-w-40"
                    />
                )}
            </CardField>
        </>
    );
}
