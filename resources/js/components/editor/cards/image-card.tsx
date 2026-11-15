import type { ReactNodeViewProps } from '@tiptap/react';
import { AlertTriangle } from 'lucide-react';
import { useId, useState } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { SelectField } from '@/components/ui/select-field';
import { Skeleton } from '@/components/ui/skeleton';
import { __ } from '@/lib/i18n';
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
 * An image card as it will look: the image at its stored width, its caption
 * typed in place under it, and an alt-text row shown while the card is
 * selected or the alt text is missing. When selected, the header offers
 * Replace and the widths. A new card opens the library at once, and closing
 * it without choosing removes the card, so no card is left naming no image.
 */
export function ImageCard({
    node,
    selected,
    updateAttributes,
    deleteNode,
}: ReactNodeViewProps) {
    const { media, loading, addMedia, manifest } = useCardEnvironment();
    const attrs = node.attrs as ImageAttrs;
    const item = attrs.mediaId ? media[attrs.mediaId] : undefined;
    const uploadedFor = useUploadTarget();
    const [altFocused, setAltFocused] = useState(false);
    const altId = useId();
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
    const widths = optionsFor(manifest, 'image', 'width');

    return (
        <>
            <CardFrame
                label={__('Image')}
                selected={selected}
                onReplace={() =>
                    open(pick(attrs.mediaId ? 'replace' : 'insert'))
                }
                replaceLabel={__('Replace the image')}
                onRemove={deleteNode}
                preview={attrs.caption ?? item?.title ?? ''}
                tools={
                    widths.length > 0 && (
                        <Segmented
                            aria-label={__('Width')}
                            value={attrs.width ?? 'column'}
                            onValueChange={(width) =>
                                updateAttributes({ width })
                            }
                        >
                            {widths.map((width) => (
                                <SegmentedItem
                                    key={width.value}
                                    value={width.value}
                                >
                                    {__(width.label)}
                                </SegmentedItem>
                            ))}
                        </Segmented>
                    )
                }
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
                        <figcaption className="mt-1 flex items-baseline gap-2">
                            <Input
                                value={attrs.caption ?? ''}
                                placeholder={__('Add a caption')}
                                aria-label={__('Caption')}
                                onChange={(event) =>
                                    updateAttributes({
                                        caption: event.target.value,
                                    })
                                }
                                className="text-small text-ink-2 h-(--ctl-h-sm) border-transparent bg-transparent px-0"
                            />
                            {attrs.credit && (
                                <span className="text-caption text-ink-3 shrink-0 uppercase">
                                    {attrs.credit}
                                </span>
                            )}
                        </figcaption>
                        {(selected || missingAlt || altFocused) && (
                            <div
                                data-slot="card-alt"
                                className="mt-1 flex items-center gap-2"
                                onFocus={() => setAltFocused(true)}
                                onBlur={() => setAltFocused(false)}
                            >
                                {missingAlt && (
                                    <span className="text-small text-warn-ink flex shrink-0 items-center gap-1">
                                        <AlertTriangle className="size-4" />
                                        {__('Alt text missing')}
                                    </span>
                                )}
                                <Label htmlFor={altId} className="shrink-0">
                                    {__('Alt text')}
                                </Label>
                                <Input
                                    id={altId}
                                    value={attrs.alt ?? ''}
                                    placeholder={item.alt ?? ''}
                                    onChange={(event) =>
                                        updateAttributes({
                                            alt: event.target.value,
                                        })
                                    }
                                    className="h-(--ctl-h-sm)"
                                />
                            </div>
                        )}
                    </figure>
                ) : attrs.mediaId && loading.includes(attrs.mediaId) ? (
                    <Skeleton aria-busy="true" className="aspect-3/2 w-full" />
                ) : (
                    <p className="text-small text-ink-2">
                        {attrs.mediaId
                            ? __(
                                  'This image is no longer in the library, so the site shows nothing here. Replace it or remove the card.',
                              )
                            : __('Choose an image from the library.')}
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
                label={__('Alt text')}
                hint={
                    item?.alt
                        ? __("Leave empty to use the library's: “:alt”.", {
                              alt: item.alt,
                          })
                        : __(
                              'What the image shows, for someone who cannot see it.',
                          )
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
                    {__('Decorative: it adds nothing a reader would miss')}
                </Label>
            </div>
            <TextSetting
                label={__('Caption')}
                value={attrs.caption}
                onChange={(caption) => updateAttributes({ caption })}
            />
            <TextSetting
                label={__('Credit')}
                value={attrs.credit}
                onChange={(credit) => updateAttributes({ credit })}
            />
            <CardField label={__('Width')}>
                {(props) => (
                    <SelectField
                        {...props}
                        value={attrs.width ?? 'column'}
                        onValueChange={(width) => updateAttributes({ width })}
                        options={optionsFor(manifest, 'image', 'width')}
                        className="w-full max-w-40"
                    />
                )}
            </CardField>
        </>
    );
}
