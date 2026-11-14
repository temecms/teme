import type { ReactNodeViewProps } from '@tiptap/react';
import { __ } from '@/lib/i18n';
import { CardFrame, TextSetting } from './card-frame';
import { useCardEnvironment, useUploadTarget } from './card-environment';
import type { CardSettingsProps } from './card-environment';
import type { PlayableMedia } from './media-file';
import { useMediaPicker } from '../pick-media';
import type { MediaPickerOptions } from '../pick-media';

type AudioAttrs = {
    mediaId: string | null;
    caption: string | null;
    transcript: string | null;
};

const MUTED = 'text-ink-2 mt-1.5 text-[13px]';

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
    const uploadedFor = useUploadTarget();
    const pick = (replacing: boolean): MediaPickerOptions => ({
        caller: 'choose',
        multi: false,
        kinds: ['audio'],
        action: replacing ? __('Replace audio') : __('Insert audio'),
        uploadedFor,
        onInsert: (ids) => {
            void addMedia(ids);
            updateAttributes({ mediaId: ids[0] });
        },
        // A new card closed without a choice is removed, as the Image card is.
        onClose: replacing ? undefined : deleteNode,
    });
    const { open, picker } = useMediaPicker(attrs.mediaId ? null : pick(false));
    const transcript = (attrs.transcript ?? '').trim();

    return (
        <>
            <CardFrame
                label={__('Audio')}
                selected={selected}
                warning={
                    item !== undefined && transcript === ''
                        ? { label: __('No transcript') }
                        : null
                }
                onReplace={() => open(pick(attrs.mediaId !== null))}
                replaceLabel={__('Replace audio')}
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
                                <span className="text-ink font-medium">
                                    Transcript
                                </span>{' '}
                                {transcript}
                            </p>
                        )}
                    </figure>
                ) : item ? (
                    <p className="text-ink-2 text-[13px]">
                        This file isn’t audio, so the site shows nothing here.
                        Replace it or remove the card.
                    </p>
                ) : attrs.mediaId && loading.includes(attrs.mediaId) ? (
                    <div
                        aria-busy="true"
                        className="bg-surface-2 h-[54px] w-full animate-pulse rounded-sm"
                    />
                ) : (
                    <p className="text-ink-2 text-[13px]">
                        {attrs.mediaId
                            ? 'This audio file is no longer in the library, so the site shows nothing here. Replace it or remove the card.'
                            : 'Choose an audio file from the library.'}
                    </p>
                )}
            </CardFrame>
            {picker}
        </>
    );
}

/** An audio card's settings, in the card's panel or the rail's inspector. */
export function AudioSettings({ attrs: raw, update }: CardSettingsProps) {
    const attrs = raw as AudioAttrs;

    return (
        <>
            <TextSetting
                label={__('Caption')}
                value={attrs.caption}
                onChange={(caption) => update({ caption })}
            />
            <TextSetting
                label={__('Transcript')}
                hint={__(
                    'Everything said, in words, for people who can’t hear it. A blank line starts a new paragraph. Readers open it under the player.',
                )}
                value={attrs.transcript}
                onChange={(transcript) => update({ transcript })}
                multiline
            />
        </>
    );
}
