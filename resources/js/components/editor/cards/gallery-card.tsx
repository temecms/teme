import type { ReactNodeViewProps } from '@tiptap/react';
import { useRef, useState } from 'react';
import { MediaModal } from '@/components/media/media-modal';
import { SelectField } from '@/components/ui/select-field';
import { largest, variant } from '@/lib/media-urls';
import { cn } from '@/lib/utils';
import { CardField, CardFrame, TextSetting } from './card-frame';
import { optionsFor, useCardEnvironment } from './card-environment';
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
    const [choosing, setChoosing] = useState(ids.length === 0);
    const chose = useRef(false);
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
                              onClick: () => setChoosing(true),
                          }
                        : null
                }
                onReplace={() => setChoosing(true)}
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
                            <figcaption className="text-muted-foreground mt-1.5 text-[13px]">
                                {attrs.caption}
                            </figcaption>
                        )}
                    </figure>
                ) : ids.some((id) => loading.includes(id)) ? (
                    <div
                        aria-busy="true"
                        className="bg-muted h-32 w-full animate-pulse rounded-sm"
                    />
                ) : (
                    <p className="text-muted-foreground text-[13px]">
                        {ids.length > 0
                            ? 'These images are no longer in the library, so the site shows nothing here.'
                            : 'Choose two or more images from the library.'}
                    </p>
                )}
                {items.length < ids.length && items.length > 0 && (
                    <p className="text-muted-foreground mt-1.5 text-xs">
                        {ids.length - items.length} of the images are no longer
                        in the library and are left out.
                    </p>
                )}
            </CardFrame>
            <MediaModal
                open={choosing}
                onOpenChange={(open) => {
                    setChoosing(open);

                    if (!open && !chose.current && ids.length === 0) {
                        deleteNode();
                    }

                    chose.current = false;
                }}
                kinds={['image']}
                multiple
                action={ids.length > 0 ? 'Use these images' : 'Insert gallery'}
                onChoose={(chosen) => {
                    chose.current = true;
                    void addMedia(chosen);
                    updateAttributes({ mediaIds: chosen.slice(0, 24) });
                }}
            />
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
