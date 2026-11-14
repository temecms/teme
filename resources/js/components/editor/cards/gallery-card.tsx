import type { ReactNodeViewProps } from '@tiptap/react';
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';
import { largest, variant } from '@/lib/media-urls';
import { cn } from '@/lib/utils';
import { CardField, CardFrame, TextSetting } from './card-frame';
import {
    optionsFor,
    useCardEnvironment,
    useUploadTarget,
} from './card-environment';
import { useMediaPicker } from '../pick-media';
import type { MediaPickerOptions } from '../pick-media';
import type { CardSettingsProps } from './card-environment';

type GalleryAttrs = {
    mediaIds: string[] | null;
    caption: string | null;
    layout: string | null;
};

/**
 * A gallery card as it will look: its images in a grid or a row, and the
 * caption. A new card opens the library at once, and closing it without
 * choosing removes the card.
 */
export function GalleryCard({
    node,
    selected,
    updateAttributes,
    deleteNode,
}: ReactNodeViewProps) {
    const { media, loading, addMedia } = useCardEnvironment();
    const attrs = node.attrs as GalleryAttrs;
    const ids = attrs.mediaIds ?? [];
    const items = ids.flatMap((id) => (media[id] ? [media[id]] : []));
    const uploadedFor = useUploadTarget();
    const pick = (adding: boolean): MediaPickerOptions => ({
        caller: 'gallery',
        multi: true,
        action: adding ? __('Insert gallery') : __('Use these images'),
        uploadedFor,
        onInsert: (chosen) => {
            void addMedia(chosen);
            updateAttributes({ mediaIds: chosen.slice(0, 24) });
        },
        // A new card closed without a choice is removed.
        onClose: adding ? deleteNode : undefined,
    });
    const { open, picker } = useMediaPicker(
        ids.length === 0 ? pick(true) : null,
    );
    const tooFew = ids.length < 2;

    return (
        <>
            <CardFrame
                label="Gallery"
                selected={selected}
                warning={
                    tooFew
                        ? {
                              label: 'Needs two images',
                              onClick: () => open(pick(ids.length === 0)),
                          }
                        : null
                }
                onReplace={() => open(pick(ids.length === 0))}
                replaceLabel="Choose the gallery's images"
                onRemove={deleteNode}
                settings={
                    <GallerySettings
                        attrs={node.attrs}
                        update={updateAttributes}
                    />
                }
            >
                {items.length > 0 ? (
                    <figure>
                        <ul
                            className={cn(
                                'gap-2',
                                attrs.layout === 'row'
                                    ? 'flex overflow-hidden'
                                    : 'grid grid-cols-3',
                            )}
                        >
                            {items.map((item, index) => (
                                <li key={ids[index]} className="min-w-0">
                                    <img
                                        src={
                                            variant(item.variants, 'w640') ??
                                            largest(item.variants) ??
                                            ''
                                        }
                                        alt={item.alt ?? ''}
                                        width={item.width}
                                        height={item.height}
                                        className="aspect-square h-auto w-full rounded-sm object-cover"
                                    />
                                </li>
                            ))}
                        </ul>
                        {attrs.caption && (
                            <figcaption className="text-ink-2 mt-1.5 text-[13px]">
                                {attrs.caption}
                            </figcaption>
                        )}
                    </figure>
                ) : ids.some((id) => loading.includes(id)) ? (
                    <div
                        aria-busy="true"
                        className="bg-surface-2 h-32 w-full animate-pulse rounded-sm"
                    />
                ) : (
                    <p className="text-ink-2 text-[13px]">
                        {ids.length > 0
                            ? 'These images are no longer in the library, so the site shows nothing here.'
                            : 'Choose two or more images from the library.'}
                    </p>
                )}
                {items.length < ids.length && items.length > 0 && (
                    <p className="text-ink-2 mt-1.5 text-xs">
                        {ids.length - items.length} of the images are no longer
                        in the library and are left out.
                    </p>
                )}
            </CardFrame>
            {picker}
        </>
    );
}

/** A gallery card's settings, in the card's panel or the rail's inspector. */
export function GallerySettings({
    attrs: raw,
    update: updateAttributes,
}: CardSettingsProps) {
    const { manifest } = useCardEnvironment();
    const attrs = raw as GalleryAttrs;

    return (
        <>
            <TextSetting
                label="Caption"
                value={attrs.caption}
                onChange={(caption) => updateAttributes({ caption })}
            />
            <CardField label="Layout">
                {(props) => (
                    <SelectField
                        {...props}
                        value={attrs.layout ?? 'grid'}
                        onValueChange={(layout) => updateAttributes({ layout })}
                        options={optionsFor(manifest, 'gallery', 'layout')}
                        className="h-[34px] w-full max-w-40"
                    />
                )}
            </CardField>
        </>
    );
}
