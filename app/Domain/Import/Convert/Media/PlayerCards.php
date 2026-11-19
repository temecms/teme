<?php

/**
 * Audio and Video cards from WordPress's players (I-446): a file by its
 * attachment ID, else its address traced as an image's is. A file Teme
 * doesn't have is left out and named; one on another site becomes a link
 * to it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert\Media;

use App\Domain\Cards\Types\VideoCard;
use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\Html\InlineContent;
use App\Domain\Import\Convert\Html\Nodes;
use Dom\Element;

final class PlayerCards
{
    /** The longest side a video's size may give (VideoCard's rule). */
    private const int LARGEST = 16384;

    /** The language a track may carry (VideoCard's rule). */
    private const string LANGUAGE = '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/';

    /**
     * An Audio card, or what stands for a file that isn't in the library.
     *
     * @return list<array<string, mixed>>
     */
    public static function audio(BlockContext $context, ?int $attachmentId, string $src, ?string $caption = null): array
    {
        $mediaId = self::find($context, $attachmentId, $src);

        if (! is_string($mediaId)) {
            return $mediaId;
        }

        return [['type' => 'audio', 'attrs' => [
            'id' => $context->newId(),
            'mediaId' => $mediaId,
            'caption' => $caption,
            'transcript' => null,
        ]]];
    }

    /**
     * A Video card, or what stands for a file that isn't in the library.
     *
     * @param  list<array{src: string, kind: string, language: string, label: string, default: bool}>  $tracks
     *
     * @return list<array<string, mixed>>
     */
    public static function video(
        BlockContext $context,
        ?int $attachmentId,
        string $src,
        ?string $caption = null,
        string $poster = '',
        ?int $width = null,
        ?int $height = null,
        bool $loop = false,
        bool $autoplay = false,
        bool $muted = false,
        array $tracks = [],
    ): array {
        $mediaId = self::find($context, $attachmentId, $src);

        if (! is_string($mediaId)) {
            return $mediaId;
        }

        $switches = [$loop, $autoplay, $muted];
        $looping = ! in_array(false, $switches, true);

        if (! $looping && in_array(true, $switches, true)) {
            $context->report->count('video.playback');
        }

        $sized = $width !== null && $height !== null && min($width, $height) >= 1 && max($width, $height) <= self::LARGEST;

        return [['type' => 'video', 'attrs' => [
            'id' => $context->newId(),
            'mediaId' => $mediaId,
            'posterMediaId' => trim($poster) === '' ? null : $context->file(null, $poster)['mediaId'],
            'caption' => $caption,
            'videoWidth' => $sized ? $width : null,
            'videoHeight' => $sized ? $height : null,
            'loop' => $looping,
            'tracks' => self::tracks($context, $tracks),
        ]]];
    }

    /**
     * The card of an `<audio>` or `<video>` element: its `src`, else its
     * first `<source>`'s, by the attachment ID when it has one; a video's
     * poster, size, switches and tracks.
     *
     * @return list<array<string, mixed>>
     */
    public static function element(Element $element, BlockContext $context, ?int $attachmentId = null): array
    {
        $src = trim((string) $element->getAttribute('src'));

        if ($src === '') {
            $src = trim((string) $element->querySelector('source[src]')?->getAttribute('src'));
        }

        if ($element->localName === 'audio') {
            return self::audio($context, $attachmentId, $src);
        }

        $tracks = [];

        foreach (iterator_to_array($element->querySelectorAll('track')) as $track) {
            $kind = strtolower(trim((string) $track->getAttribute('kind')));
            $tracks[] = [
                'src' => (string) $track->getAttribute('src'),
                'kind' => $kind === '' ? 'subtitles' : $kind,
                'language' => trim((string) $track->getAttribute('srclang')),
                'label' => (string) $track->getAttribute('label'),
                'default' => $track->hasAttribute('default'),
            ];
        }

        $size = fn (string $name): ?int => is_int($found = filter_var($element->getAttribute($name), FILTER_VALIDATE_INT)) ? $found : null;

        return self::video(
            $context,
            $attachmentId,
            $src,
            poster: (string) $element->getAttribute('poster'),
            width: $size('width'),
            height: $size('height'),
            loop: $element->hasAttribute('loop'),
            autoplay: $element->hasAttribute('autoplay'),
            muted: $element->hasAttribute('muted'),
            tracks: $tracks,
        );
    }

    /**
     * The media ID of a file, or the nodes that stand for one Teme doesn't
     * have: nothing and `media.missing` for the site's own, a link and
     * `media.outside` for another site's.
     *
     * @return string|list<array<string, mixed>>
     */
    private static function find(BlockContext $context, ?int $attachmentId, string $src): string|array
    {
        $found = $context->file($attachmentId, $src);

        if ($found['mediaId'] !== null) {
            return $found['mediaId'];
        }

        if ($src === '' || $found['own']) {
            $context->report->count('media.missing', '', $src === '' ? null : $found['file']);

            return [];
        }

        $context->report->count('media.outside', '', $found['host']);
        $href = InlineContent::href($src);
        $words = $found['file'] !== '' ? $found['file'] : $src;

        return [Nodes::paragraph([['type' => 'text', 'text' => $words, ...($href === null ? [] : ['marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]])]])];
    }

    /**
     * The tracks a Video card keeps (I-446): a traced file, a kind the card
     * takes, a language; at most eight, and one on by default. Each one
     * left out is said.
     *
     * @param  list<array{src: string, kind: string, language: string, label: string, default: bool}>  $tracks
     *
     * @return list<array{mediaId: string, language: string, kind: string, label: string|null, default: bool}>|null
     */
    private static function tracks(BlockContext $context, array $tracks): ?array
    {
        $kept = [];
        $chosen = false;

        foreach ($tracks as $track) {
            $mediaId = $context->file(null, $track['src'])['mediaId'];

            $language = preg_match(self::LANGUAGE, $track['language']) === 1 && strlen($track['language']) <= 35;

            if ($mediaId === null || ! in_array($track['kind'], VideoCard::TRACK_KINDS, true) || ! $language || count($kept) === 8) {
                $context->report->count('video.tracks');

                continue;
            }

            $label = trim($track['label']);
            $kept[] = ['mediaId' => $mediaId, 'language' => $track['language'], 'kind' => $track['kind'], 'label' => $label === '' ? null : mb_substr($label, 0, 100), 'default' => $track['default'] && ! $chosen];
            $chosen = $chosen || $track['default'];
        }

        return $kept === [] ? null : $kept;
    }
}
