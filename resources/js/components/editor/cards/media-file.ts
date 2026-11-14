import type { CardMedia, MediaKind } from '@/types';

/**
 * A media item as CardMedia gives it, with the facts a sound or video file
 * needs: its kind, its own address (null for an image) and its type (I-446).
 */
export type PlayableMedia = CardMedia & {
    kind: MediaKind;
    fileUrl: string | null;
    mimeType: string;
    uploadedAt: string;
};

/** The kinds of caption track a reader's player shows, as VideoCard's rule. */
export const TRACK_KINDS = [
    'captions',
    'subtitles',
    'descriptions',
    'chapters',
] as const;

export type TrackKind = (typeof TRACK_KINDS)[number];

/** A caption track as a video card stores it. */
export type CaptionTrack = {
    mediaId: string;
    language: string;
    kind: TrackKind;
    label: string | null;
    default: boolean;
};

/** The most tracks a video holds, as VideoCard's rule. */
export const MOST_TRACKS = 8;

/** A language tag, as a track's `srclang` takes it and VideoCard checks it. */
const LANGUAGE = /^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/;

/** Whether this is a language tag VideoCard accepts, at most 35 characters. */
export function isLanguageTag(value: string): boolean {
    return value.length <= 35 && LANGUAGE.test(value);
}

/** The largest side a video's stored size may have, as VideoCard's rule. */
const LARGEST_SIDE = 16384;

/**
 * A new track for this WebVTT file, as Add a track makes it: captions, in
 * this language when it's a language tag and English otherwise, labelled
 * with that language's own name, and on by default only when it's the first.
 */
export function newTrack(
    mediaId: string,
    language: string,
    first: boolean,
): CaptionTrack {
    const tag = isLanguageTag(language) ? language : 'en';
    let label: string | null = null;

    try {
        label =
            new Intl.DisplayNames([tag], { type: 'language' }).of(tag) ?? null;
    } catch {
        label = null;
    }

    return { mediaId, language: tag, kind: 'captions', label, default: first };
}

/**
 * A video card's tracks as a list, whatever the attribute holds: a pasted
 * card may hold words instead of a list.
 */
export function tracksOf(value: unknown): CaptionTrack[] {
    if (!Array.isArray(value)) {
        return [];
    }

    return value.filter(
        (track): track is CaptionTrack =>
            typeof track === 'object' &&
            track !== null &&
            typeof (track as { mediaId?: unknown }).mediaId === 'string',
    );
}

/**
 * The size the browser read from a video file, as the card stores it: both
 * sides, or neither when it read none (a file with only sound gives 0 by 0).
 */
export function sizeOf(video: { videoWidth: number; videoHeight: number }): {
    videoWidth: number | null;
    videoHeight: number | null;
} {
    const side = (value: number): number | null =>
        Number.isInteger(value) && value >= 1 && value <= LARGEST_SIDE
            ? value
            : null;
    const width = side(video.videoWidth);
    const height = side(video.videoHeight);

    return width === null || height === null
        ? { videoWidth: null, videoHeight: null }
        : { videoWidth: width, videoHeight: height };
}

/** Whether a library row is a WebVTT file, the only file a caption track takes. */
export function isCaptionFile(row: {
    kind: MediaKind;
    extension: string;
}): boolean {
    return row.kind === 'document' && row.extension.toLowerCase() === 'vtt';
}
