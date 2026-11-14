import type { ReactNodeViewProps } from '@tiptap/react';
import { useId, useRef, useState } from 'react';
import { MediaModal } from '@/components/media/media-modal';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import { largest, variant } from '@/lib/media-urls';
import { CardField, CardFrame, TextSetting } from './card-frame';
import { useCardEnvironment } from './card-environment';
import type { CardSettingsProps } from './card-environment';
import {
    MOST_TRACKS,
    TRACK_KINDS,
    isCaptionFile,
    isLanguageTag,
    newTrack,
    sizeOf,
    tracksOf,
} from './media-file';
import type { CaptionTrack, PlayableMedia, TrackKind } from './media-file';

type VideoAttrs = {
    mediaId: string | null;
    posterMediaId: string | null;
    caption: string | null;
    videoWidth: number | null;
    videoHeight: number | null;
    loop: boolean | null;
    tracks: unknown;
};

const KIND_LABELS: Record<TrackKind, string> = {
    captions: 'Captions',
    subtitles: 'Subtitles',
    descriptions: 'Descriptions',
    chapters: 'Chapters',
};

const MUTED = 'text-ink-2 mt-1.5 text-[13px]';

/**
 * A video card as it will play: the file with its poster and size, the
 * browser's controls (none in loop mode) and the caption. A new card opens
 * the library at once; the size is read from the file when it's chosen.
 */
export function VideoCard({
    node,
    selected,
    updateAttributes,
    deleteNode,
}: ReactNodeViewProps) {
    const { media, loading, addMedia } = useCardEnvironment();
    const attrs = node.attrs as VideoAttrs;
    const item = attrs.mediaId
        ? (media[attrs.mediaId] as PlayableMedia | undefined)
        : undefined;
    const poster = attrs.posterMediaId ? media[attrs.posterMediaId] : undefined;
    const [choosing, setChoosing] = useState(!attrs.mediaId);
    const chose = useRef(false);
    // Set when a file is chosen here, so its size is read once it loads.
    const measuring = useRef(false);
    const loop = attrs.loop === true;
    const noCaptions =
        item !== undefined && !loop && tracksOf(attrs.tracks).length === 0;

    return (
        <>
            <CardFrame
                label="Video"
                selected={selected}
                warning={noCaptions ? { label: 'No captions' } : null}
                onReplace={() => setChoosing(true)}
                replaceLabel="Replace video"
                onRemove={deleteNode}
                settings={
                    <VideoSettings
                        attrs={node.attrs}
                        update={updateAttributes}
                    />
                }
            >
                {item?.kind === 'video' && item.fileUrl ? (
                    <figure>
                        <video
                            src={item.fileUrl}
                            poster={
                                poster
                                    ? (variant(poster.variants, 'w1280') ??
                                      largest(poster.variants) ??
                                      undefined)
                                    : undefined
                            }
                            width={attrs.videoWidth ?? undefined}
                            height={attrs.videoHeight ?? undefined}
                            controls={!loop}
                            muted={loop}
                            loop={loop}
                            preload="metadata"
                            playsInline
                            onLoadedMetadata={(event) => {
                                if (measuring.current) {
                                    measuring.current = false;
                                    updateAttributes(
                                        sizeOf(event.currentTarget),
                                    );
                                }
                            }}
                            className="bg-surface-2 h-auto w-full rounded-sm"
                        />
                        {loop && (
                            <p className={MUTED}>
                                Loops silently, with no controls.
                            </p>
                        )}
                        {attrs.caption && (
                            <figcaption className={MUTED}>
                                {attrs.caption}
                            </figcaption>
                        )}
                    </figure>
                ) : item ? (
                    <p className="text-ink-2 text-[13px]">
                        This file isn’t a video, so the site shows nothing here.
                        Replace it or remove the card.
                    </p>
                ) : attrs.mediaId && loading.includes(attrs.mediaId) ? (
                    <div
                        aria-busy="true"
                        className="bg-surface-2 aspect-video w-full animate-pulse rounded-sm"
                    />
                ) : (
                    <p className="text-ink-2 text-[13px]">
                        {attrs.mediaId
                            ? 'This video is no longer in the library, so the site shows nothing here. Replace it or remove the card.'
                            : 'Choose a video from the library.'}
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
                kinds={['video']}
                action={attrs.mediaId ? 'Replace video' : 'Insert video'}
                onChoose={(ids) => {
                    chose.current = true;

                    // The same file again: its size is already kept, and no new load would read it.
                    if (ids[0] === attrs.mediaId) {
                        return;
                    }

                    measuring.current = true;
                    void addMedia(ids);
                    updateAttributes({
                        mediaId: ids[0],
                        videoWidth: null,
                        videoHeight: null,
                    });
                }}
            />
        </>
    );
}

/** A video card's settings, in the card's panel or the rail's inspector. */
export function VideoSettings({ attrs: raw, update }: CardSettingsProps) {
    const { media, addMedia } = useCardEnvironment();
    const attrs = raw as VideoAttrs;
    const tracks = tracksOf(attrs.tracks);
    const poster = attrs.posterMediaId ? media[attrs.posterMediaId] : undefined;
    const [choosingPoster, setChoosingPoster] = useState(false);
    const [choosingTrack, setChoosingTrack] = useState(false);
    const [refusal, setRefusal] = useState<string | null>(null);
    const id = useId();

    // An empty list is stored as none, so a card copied without tracks keeps them.
    const setTracks = (next: CaptionTrack[]) =>
        update({ tracks: next.length === 0 ? null : next });

    // Turning one track on by default turns the others off.
    const change = (index: number, patch: Partial<CaptionTrack>) =>
        setTracks(
            tracks.map((track, at) =>
                at === index
                    ? { ...track, ...patch }
                    : patch.default === true
                      ? { ...track, default: false }
                      : track,
            ),
        );

    return (
        <>
            <div
                className="grid gap-1.5"
                role="group"
                aria-labelledby={`${id}-poster`}
                aria-describedby={`${id}-poster-hint`}
            >
                <span
                    id={`${id}-poster`}
                    className="text-sm leading-none font-medium"
                >
                    Poster
                </span>
                {poster && (
                    <img
                        src={variant(poster.variants) ?? ''}
                        alt=""
                        className="h-16 w-28 rounded-sm object-cover"
                    />
                )}
                <div className="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setChoosingPoster(true)}
                    >
                        {attrs.posterMediaId
                            ? 'Replace poster'
                            : 'Choose poster'}
                    </Button>
                    {attrs.posterMediaId && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => update({ posterMediaId: null })}
                        >
                            Remove poster
                        </Button>
                    )}
                </div>
                <p id={`${id}-poster-hint`} className="text-ink-2 text-xs">
                    The picture shown before it plays. Search engines list a
                    video only when it has one.
                </p>
            </div>
            <TextSetting
                label="Caption"
                value={attrs.caption}
                onChange={(caption) => update({ caption })}
            />
            <div className="grid gap-1.5">
                <div className="flex items-center gap-2">
                    <Checkbox
                        id={`${id}-loop`}
                        aria-describedby={`${id}-loop-hint`}
                        checked={attrs.loop === true}
                        onCheckedChange={(checked) =>
                            update({ loop: checked === true })
                        }
                    />
                    <Label htmlFor={`${id}-loop`}>
                        Loop: play on its own, silently, again and again, with
                        no controls
                    </Label>
                </div>
                <p id={`${id}-loop-hint`} className="text-ink-2 text-xs">
                    For a short clip without sound, used like a moving picture.
                </p>
            </div>
            <div
                className="grid gap-2"
                role="group"
                aria-labelledby={`${id}-tracks`}
                aria-describedby={`${id}-tracks-hint`}
            >
                <span
                    id={`${id}-tracks`}
                    className="text-sm leading-none font-medium"
                >
                    Caption tracks
                </span>
                <p id={`${id}-tracks-hint`} className="text-ink-2 text-xs">
                    WebVTT files (.vtt) from the library, so people who can’t
                    hear the video can read it.
                </p>
                {tracks.map((track, index) => (
                    <TrackRow
                        key={`${track.mediaId}-${index}`}
                        track={track}
                        title={media[track.mediaId]?.title ?? null}
                        onChange={(patch) => change(index, patch)}
                        onRemove={() =>
                            setTracks(tracks.filter((_, at) => at !== index))
                        }
                    />
                ))}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="justify-self-start"
                    disabled={tracks.length >= MOST_TRACKS}
                    onClick={() => {
                        setRefusal(null);
                        setChoosingTrack(true);
                    }}
                >
                    Add a track
                </Button>
                {tracks.length >= MOST_TRACKS && (
                    <p className="text-ink-2 text-xs">
                        A video holds up to 8 tracks.
                    </p>
                )}
                {refusal && (
                    <p role="alert" className="text-danger-ink text-xs">
                        {refusal}
                    </p>
                )}
            </div>
            <MediaModal
                open={choosingPoster}
                onOpenChange={setChoosingPoster}
                kinds={['image']}
                action="Use as poster"
                onChoose={(ids) => {
                    void addMedia(ids);
                    update({ posterMediaId: ids[0] });
                }}
            />
            <MediaModal
                open={choosingTrack}
                onOpenChange={setChoosingTrack}
                kinds={['document']}
                action="Add track"
                onChoose={(ids, rows) => {
                    const row = rows.find(
                        (candidate) => candidate.id === ids[0],
                    );

                    if (row === undefined || !isCaptionFile(row)) {
                        setRefusal(
                            'That file isn’t a WebVTT file (.vtt), so it can’t be a caption track.',
                        );

                        return;
                    }

                    void addMedia(ids);
                    setTracks([
                        ...tracks,
                        newTrack(
                            ids[0],
                            document.documentElement.lang,
                            tracks.length === 0,
                        ),
                    ]);
                }}
            />
        </>
    );
}

/** One caption track's settings: its file, language, kind, label and default. */
function TrackRow({
    track,
    title,
    onChange,
    onRemove,
}: {
    track: CaptionTrack;
    title: string | null;
    onChange: (patch: Partial<CaptionTrack>) => void;
    onRemove: () => void;
}) {
    const id = useId();
    const name = title ?? 'This file is no longer in the library.';
    // What's typed stays here until it's a tag the server accepts, so a save
    // never meets a track it refuses (I-452).
    const [language, setLanguage] = useState(track.language);
    const typedIsTag = isLanguageTag(language.trim());

    return (
        <div
            className="border-line grid gap-2 rounded-md border p-2"
            role="group"
            aria-label={name}
        >
            <p className="truncate text-[13px] font-medium">{name}</p>
            <TextSetting
                label="Language"
                hint={
                    typedIsTag
                        ? 'A language tag, such as en, fr or pt-BR.'
                        : `Not a language tag yet, so the track keeps ${track.language}. A tag looks like en, fr or pt-BR.`
                }
                value={language}
                onChange={(typed) => {
                    setLanguage(typed);

                    if (isLanguageTag(typed.trim())) {
                        onChange({ language: typed.trim() });
                    }
                }}
            />
            <CardField
                label="Kind"
                hint="Captions write out the words and the sounds; subtitles translate the words; descriptions say what happens on screen; chapters name the parts to jump to."
            >
                {(props) => (
                    <SelectField
                        {...props}
                        value={track.kind}
                        onValueChange={(kind) =>
                            onChange({ kind: kind as TrackKind })
                        }
                        options={TRACK_KINDS.map((kind) => ({
                            value: kind,
                            label: KIND_LABELS[kind],
                        }))}
                        className="h-[34px] w-full max-w-40"
                    />
                )}
            </CardField>
            <TextSetting
                label="Label"
                hint="What the player’s menu shows, such as English."
                value={track.label}
                onChange={(label) =>
                    onChange({
                        label: label === '' ? null : label.slice(0, 100),
                    })
                }
            />
            <div className="flex items-center gap-2">
                <Checkbox
                    id={`${id}-default`}
                    checked={track.default}
                    onCheckedChange={(checked) =>
                        onChange({ default: checked === true })
                    }
                />
                <Label htmlFor={`${id}-default`}>On by default</Label>
            </div>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="justify-self-start"
                aria-label={`Remove track ${name}`}
                onClick={onRemove}
            >
                Remove track
            </Button>
        </div>
    );
}
