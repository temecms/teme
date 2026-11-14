<?php

declare(strict_types=1);

namespace App\Domain\Cards\Types;

use App\Domain\Cards\CardAttribute;
use App\Domain\Cards\CardMedia;
use App\Domain\Cards\CardMenuEntry;
use App\Domain\Cards\CardReference;
use App\Domain\Cards\CardType;
use App\Domain\Cards\ChangeList;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\RenderContext;
use App\Domain\Cards\StructuredDataCard;
use App\Domain\Site\Document\JsonLdNode;
use App\Domain\Site\SiteDates;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\StructuredData\NodeIds;

/**
 * One video file from the library, played by the browser's own player with
 * its poster, its size, a caption and caption tracks, or looping silently
 * like a moving picture (I-446). Every file is held by ID and its address
 * worked out when drawn, never stored.
 */
final class VideoCard implements CardType, StructuredDataCard
{
    /** The kinds of caption track a reader's player shows; HTML's `metadata` is for scripts. */
    public const array TRACK_KINDS = ['captions', 'subtitles', 'descriptions', 'chapters'];

    /** A BCP 47 language tag, as a track's `srclang` takes it: `en`, `pt-BR`, `zh-Hant`. */
    private const string LANGUAGE = '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/';

    /** The poster's width to aim for: the content column at twice its density. */
    private const int POSTER_WIDTH = 1280;

    /** The largest side a video's stored size may have. */
    private const int LARGEST_SIDE = 16384;

    public function name(): string
    {
        return 'video';
    }

    public function label(): string
    {
        return 'Video';
    }

    public function description(): string
    {
        return 'A video file from the library, played in the page, with a poster, a caption and caption tracks.';
    }

    public function since(): string
    {
        return '0.1.0';
    }

    public function attributes(): array
    {
        return [
            'id' => new CardAttribute('id', ['required', 'string', 'regex:'.DocumentSchema::NODE_ID]),
            'mediaId' => new CardAttribute('mediaId', ['required', 'string', 'size:26']),
            'posterMediaId' => new CardAttribute('posterMediaId', ['nullable', 'string', 'size:26']),
            'caption' => new CardAttribute('caption', ['nullable', 'string', 'max:2000']),
            'videoWidth' => new CardAttribute('videoWidth', ['nullable', 'integer', 'min:1', 'max:'.self::LARGEST_SIDE]),
            'videoHeight' => new CardAttribute('videoHeight', ['nullable', 'integer', 'min:1', 'max:'.self::LARGEST_SIDE]),
            'loop' => new CardAttribute('loop', ['boolean'], false),
            'tracks' => new CardAttribute('tracks', ['nullable', 'array', 'list', 'max:8']),
        ];
    }

    public function menu(): CardMenuEntry
    {
        return new CardMenuEntry('Video', 'Cards', ['video', 'film', 'movie', 'clip', 'mp4'], 'video');
    }

    public function renderHtml(array $attrs, RenderContext $context): string
    {
        $file = $context->media[(string) ($attrs['mediaId'] ?? '')] ?? null;

        if ($file === null || $file['kind'] !== 'video' || $file['fileUrl'] === null) {
            return '';
        }

        $loop = (bool) ($attrs['loop'] ?? false);
        $poster = self::posterUrl($context->media[(string) ($attrs['posterMediaId'] ?? '')] ?? null);
        $width = self::side($attrs['videoWidth'] ?? null);
        $height = self::side($attrs['videoHeight'] ?? null);
        $caption = trim((string) ($attrs['caption'] ?? ''));
        $tracks = '';

        foreach (self::tracksOf($attrs['tracks'] ?? null) as $track) {
            $item = $context->media[$track['mediaId']] ?? null;

            // A track whose file has gone or isn't WebVTT is left out; the video still plays.
            if ($item === null || $item['mimeType'] !== 'text/vtt' || $item['fileUrl'] === null) {
                continue;
            }

            $tracks .= sprintf(
                '<track kind="%s" src="%s" srclang="%s"%s%s>',
                e($track['kind']),
                e($item['fileUrl']),
                e($track['language']),
                $track['label'] === '' ? '' : ' label="'.e($track['label']).'"',
                $track['default'] ? ' default' : '',
            );
        }

        $video = sprintf(
            '<video src="%s"%s preload="metadata" playsinline%s%s>%s</video>',
            e($file['fileUrl']),
            $loop ? ' autoplay loop muted' : ' controls',
            $poster === null ? '' : ' poster="'.e($poster).'"',
            $width === null || $height === null ? '' : ' width="'.$width.'" height="'.$height.'"',
            $tracks,
        );

        return sprintf(
            '<figure class="%s">%s%s</figure>',
            $loop ? 'card-video card-video--loop' : 'card-video',
            $video,
            $caption === '' ? '' : '<figcaption>'.e($caption).'</figcaption>',
        );
    }

    public function plainText(array $attrs): string
    {
        return trim((string) ($attrs['caption'] ?? ''));
    }

    /**
     * The file, the poster and every track's file, each once.
     */
    public function references(array $attrs): array
    {
        $references = [];

        foreach ([$attrs['mediaId'] ?? null, $attrs['posterMediaId'] ?? null, ...array_column(self::tracksOf($attrs['tracks'] ?? null), 'mediaId')] as $id) {
            if (is_string($id) && $id !== '' && ! isset($references[$id])) {
                $references[$id] = CardReference::media($id);
            }
        }

        return array_values($references);
    }

    /**
     * The video as structured data (I-704): a VideoObject at the card's own
     * address on the page, and only with a poster, since search engines need
     * a thumbnail and none is made for a video by itself. It reads the files
     * the page's body has read already.
     */
    public function structuredData(array $attrs, string $page): array
    {
        $fileId = (string) ($attrs['mediaId'] ?? '');
        $posterId = (string) ($attrs['posterMediaId'] ?? '');
        $media = app(CardMedia::class)->forIds(array_values(array_filter([$fileId, $posterId])));
        $file = $media[$fileId] ?? null;
        $poster = self::posterUrl($media[$posterId] ?? null);
        $id = $attrs['id'] ?? null;

        if ($file === null || $file['kind'] !== 'video' || $file['fileUrl'] === null || $poster === null || ! is_string($id)) {
            return [];
        }

        $caption = trim((string) ($attrs['caption'] ?? ''));

        return [new JsonLdNode([
            '@id' => $page.NodeIds::card($this->name(), $id),
            '@type' => 'VideoObject',
            'name' => $caption !== '' ? $caption : $file['title'],
            'thumbnailUrl' => url($poster),
            'uploadDate' => SiteDates::inSite($file['uploadedAt'])->format(DATE_ATOM),
            'contentUrl' => url($file['fileUrl']),
        ])];
    }

    /**
     * Each caption track is a file by ID with a language tag, a kind a
     * player shows, an optional label and whether it's on by default, and
     * at most one is. The attribute's own rules cover the list.
     */
    public function validate(array $attrs): void
    {
        $tracks = is_array($attrs['tracks'] ?? null) ? $attrs['tracks'] : [];

        Validator::make(['tracks' => $tracks], [
            'tracks.*' => ['array:mediaId,language,kind,label,default'],
            'tracks.*.mediaId' => ['required', 'string', 'size:26'],
            'tracks.*.language' => ['required', 'string', 'max:35', 'regex:'.self::LANGUAGE],
            'tracks.*.kind' => ['required', 'in:'.implode(',', self::TRACK_KINDS)],
            'tracks.*.label' => ['nullable', 'string', 'max:100'],
            'tracks.*.default' => ['boolean'],
        ])->validate();

        $defaults = array_filter($tracks, fn (mixed $track): bool => is_array($track) && ($track['default'] ?? false) === true);

        if (count($defaults) > 1) {
            throw ValidationException::withMessages(['tracks' => __('Only one caption track can be on by default.')]);
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string
    {
        $parts = [];

        if (ChangeList::differs($before, $after, 'mediaId')) {
            $parts[] = 'video replaced';
        }

        $hadPoster = is_string($before['posterMediaId'] ?? null) && $before['posterMediaId'] !== '';
        $hasPoster = is_string($after['posterMediaId'] ?? null) && $after['posterMediaId'] !== '';

        if ($hadPoster !== $hasPoster) {
            $parts[] = $hasPoster ? 'poster added' : 'poster removed';
        } elseif (ChangeList::differs($before, $after, 'posterMediaId')) {
            $parts[] = 'poster replaced';
        }

        if (ChangeList::differs($before, $after, 'caption')) {
            $parts[] = 'caption changed';
        }

        if ((bool) ($before['loop'] ?? false) !== (bool) ($after['loop'] ?? false)) {
            $parts[] = (bool) ($after['loop'] ?? false) ? 'loop turned on' : 'loop turned off';
        }

        $old = self::tracksOf($before['tracks'] ?? null);
        $new = self::tracksOf($after['tracks'] ?? null);
        $added = count(array_diff(array_column($new, 'mediaId'), array_column($old, 'mediaId')));
        $removed = count(array_diff(array_column($old, 'mediaId'), array_column($new, 'mediaId')));

        if ($added > 0) {
            $parts[] = $added === 1 ? '1 caption track added' : "{$added} caption tracks added";
        }

        if ($removed > 0) {
            $parts[] = $removed === 1 ? '1 caption track removed' : "{$removed} caption tracks removed";
        }

        if ($added === 0 && $removed === 0 && $old !== $new) {
            $parts[] = 'caption tracks changed';
        }

        return ChangeList::line($this->label(), $parts);
    }

    /**
     * The poster's address: its width variant nearest POSTER_WIDTH, the wider
     * of two as near; null for a poster that has gone or has no width made,
     * as a file that isn't an image has none.
     *
     * @param  array{variants: array<string, array{path: string, url: string, width: int, height: int, bytes: int}>}|null  $item
     */
    private static function posterUrl(?array $item): ?string
    {
        $best = null;

        foreach ($item['variants'] ?? [] as $key => $variant) {
            if (preg_match('/^w\d+$/', (string) $key) !== 1) {
                continue;
            }

            $distance = abs($variant['width'] - self::POSTER_WIDTH);
            $bestDistance = $best === null ? PHP_INT_MAX : abs($best['width'] - self::POSTER_WIDTH);

            if ($distance < $bestDistance || ($best !== null && $distance === $bestDistance && $variant['width'] > $best['width'])) {
                $best = $variant;
            }
        }

        return $best === null ? null : $best['url'];
    }

    /**
     * The tracks as they're drawn: each with a file ID, a kind a player
     * shows and a language tag. Anything else, which only an autosave or a
     * pasted card can hold, is left out.
     *
     * @return list<array{mediaId: string, language: string, kind: string, label: string, default: bool}>
     */
    private static function tracksOf(mixed $tracks): array
    {
        if (! is_array($tracks) || ! array_is_list($tracks)) {
            return [];
        }

        $kept = [];

        foreach ($tracks as $track) {
            if (! is_array($track) || ! is_string($track['mediaId'] ?? null) || ! is_string($track['language'] ?? null)
                || ! is_string($track['kind'] ?? null) || ! in_array($track['kind'], self::TRACK_KINDS, true)
                || preg_match(self::LANGUAGE, $track['language']) !== 1) {
                continue;
            }

            $kept[] = [
                'mediaId' => $track['mediaId'],
                'language' => $track['language'],
                'kind' => $track['kind'],
                'label' => is_string($track['label'] ?? null) ? trim($track['label']) : '',
                'default' => ($track['default'] ?? false) === true,
            ];
        }

        return $kept;
    }

    /**
     * One side of the video's stored size, or null when it isn't a whole
     * number of pixels from 1 to LARGEST_SIDE.
     */
    private static function side(mixed $value): ?int
    {
        return is_int($value) && $value >= 1 && $value <= self::LARGEST_SIDE ? $value : null;
    }
}
