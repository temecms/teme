import type { ReactNodeViewProps } from '@tiptap/react';
import { useRef, useState } from 'react';
import { MediaModal } from '@/components/media/media-modal';
import { CardFrame, TextSetting } from './card-frame';
import { useCardEnvironment } from './card-environment';
import type { CardSettingsProps } from './card-environment';
import type { PlayableMedia } from './media-file';

type AudioAttrs = {
    mediaId: string | null;
    caption: string | null;
    transcript: string | null;
};

const MUTED = 'text-muted-foreground mt-1.5 text-[13px]';

/**
 * An audio card as it will play: the browser's player, the caption, and the
 * transcript's first lines. A new card opens the library at once, and
 * closing it without choosing removes the card, as the Image card does.
 */
export function AudioCard({
    node,
    selected,
    updateAttributes,
    deleteNode,
}: ReactNodeViewProps) {
    const { media, loading, addMedia } = useCardEnvironment();
    const attrs = node.attrs as AudioAttrs;
    const item = attrs.mediaId
        ? (media[attrs.mediaId] as PlayableMedia | undefined)
        : undefined;
    const [choosing, setChoosing] = useState(!attrs.mediaId);
    const chose = useRef(false);
    const transcript = (attrs.transcript ?? '').trim();

    return (
        <>
            <CardFrame
                label="Audio"
                selected={selected}
                warning={
                    item !== undefined && transcript === ''
                        ? { label: 'No transcript' }
                        : null
                }
                onReplace={() => setChoosing(true)}
                replaceLabel="Replace audio"
                onRemove={deleteNode}
                settings={
                    <AudioSettings
                        attrs={node.attrs}
                        update={updateAttributes}
                    />
                }
            >
                {item?.kind === 'audio' && item.fileUrl ? (
                    <figure>
                        <audio
                            src={item.fileUrl}
                            controls
                            preload="metadata"
                            className="w-full"
                        />
                        {attrs.caption && (
                            <figcaption className={MUTED}>
                                {attrs.caption}
                            </figcaption>
                        )}
                        {transcript !== '' && (
                            <p className={`${MUTED} line-clamp-2`}>
                                <span className="text-foreground font-medium">
                                    Transcript
                                </span>{' '}
                                {transcript}
                            </p>
                        )}
                    </figure>
                ) : item ? (
                    <p className="text-muted-foreground text-[13px]">
                        This file isn’t audio, so the site shows nothing here.
                        Replace it or remove the card.
                    </p>
                ) : attrs.mediaId && loading.includes(attrs.mediaId) ? (
                    <div
                        aria-busy="true"
                        className="bg-muted h-[54px] w-full animate-pulse rounded-sm"
                    />
                ) : (
                    <p className="text-muted-foreground text-[13px]">
                        {attrs.mediaId
                            ? 'This audio file is no longer in the library, so the site shows nothing here. Replace it or remove the card.'
                            : 'Choose an audio file from the library.'}
                    </p>
                )}
            </CardFrame>
            <MediaModal
                open={choosing}
                onOpenChange={(open) => {
                    setChoosing(open);

                    if (!open && !chose.current && !attrs.mediaId) {
                        deleteNode();
                    }

                    chose.current = false;
                }}
                kinds={['audio']}
                action={attrs.mediaId ? 'Replace audio' : 'Insert audio'}
                onChoose={(ids) => {
                    chose.current = true;
                    void addMedia(ids);
                    updateAttributes({ mediaId: ids[0] });
                }}
            />
        </>
    );
}

/** An audio card's settings, in the card's panel or the rail's inspector. */
export function AudioSettings({ attrs: raw, update }: CardSettingsProps) {
    const attrs = raw as AudioAttrs;

    return (
        <>
            <TextSetting
                label="Caption"
                value={attrs.caption}
                onChange={(caption) => update({ caption })}
            />
            <TextSetting
                label="Transcript"
                hint="Everything said, in words, for people who can’t hear it. A blank line starts a new paragraph. Readers open it under the player."
                value={attrs.transcript}
                onChange={(transcript) => update({ transcript })}
                multiline
            />
        </>
    );
}
