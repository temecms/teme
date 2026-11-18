<?php

/**
 * Known players' frames as Embed cards (I-164): YouTube's, Vimeo's and
 * Spotify's frame addresses written back as the page addresses
 * config/embeds.php frames. Any other frame is pruned and counted.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert\Html;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\Blocks\Embeds;
use Dom\Element;

final class Frames
{
    /** A player's frame address, and its provider's page address. */
    private const array PLAYERS = [
        ['~^https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]+)~i', 'https://www.youtube.com/watch?v=$1'],
        ['~^https?://player\.vimeo\.com/video/(\d+)~i', 'https://vimeo.com/$1'],
        ['~^https?://open\.spotify\.com/embed/(track|album|playlist|episode|show)/([A-Za-z0-9]+)~i', 'https://open.spotify.com/$1/$2'],
    ];

    /**
     * The page address of a known player's frame address, or null.
     */
    public static function address(string $src): ?string
    {
        foreach (self::PLAYERS as [$pattern, $page]) {
            if (preg_match($pattern, trim($src), $found) === 1) {
                return str_replace(['$1', '$2'], [$found[1], $found[2] ?? ''], $page);
            }
        }

        return null;
    }

    /**
     * An Embed card for a known player's frame, read from `src`, or from a
     * lazy-loading plugin's `data-src` or `data-lazy-src` behind a blank
     * `src`; null for any other frame.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function embed(Element $frame, BlockContext $context): ?array
    {
        foreach (['src', 'data-src', 'data-lazy-src'] as $attribute) {
            $src = trim((string) $frame->getAttribute($attribute));

            if ($src === '' || preg_match('~^(?:about:blank|data:)~i', $src) === 1) {
                continue;
            }

            $url = self::address($src);

            return $url === null ? null : Embeds::card($context, $url);
        }

        return null;
    }
}
