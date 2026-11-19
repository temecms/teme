<?php

/**
 * The pictures of a feed: the one variant a reader is given, nearest a width, at an absolute address, and the plain `<img>` of a card, with none of the page's loading hints.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds;

final readonly class FeedImages
{
    public const int BODY_WIDTH = 1000;

    public const int LEAD_WIDTH = 1200;

    public function __construct(private FeedUrls $urls) {}

    /**
     * The width variant nearest this width, the wider of two as near, with
     * its address absolute and its media type from its file's extension; null
     * when no width has been made yet.
     *
     * @param  array{variants: array<string, array{path: string, url: string, width: int, height: int, bytes: int}>, width: int, height: int, alt: string|null, title: string, focalX: int, focalY: int}  $item
     *
     * @return array{url: string, type: string|null, width: int, height: int}|null
     */
    public function variant(array $item, int $target): ?array
    {
        $best = null;

        foreach ($item['variants'] as $key => $variant) {
            if (preg_match('/^w\d+$/', (string) $key) !== 1) {
                continue;
            }

            $distance = abs($variant['width'] - $target);
            $bestDistance = $best === null ? PHP_INT_MAX : abs($best['width'] - $target);

            if ($distance < $bestDistance || ($distance === $bestDistance && $variant['width'] > ($best['width'] ?? 0))) {
                $best = $variant;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'url' => $this->urls->absolute($best['url']),
            'type' => match (strtolower(pathinfo($best['path'], PATHINFO_EXTENSION))) {
                'webp' => 'image/webp',
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'avif' => 'image/avif',
                default => null,
            },
            'width' => $best['width'],
            'height' => $best['height'],
        ];
    }

    /**
     * The `<img>` for one media item as a feed reader is given it: `src`,
     * `alt`, `width` and `height` only. The alt is the card's, else the
     * media's own, and empty when the picture is decorative. '' when no
     * width has been made yet.
     *
     * @param  array{variants: array<string, array{path: string, url: string, width: int, height: int, bytes: int}>, width: int, height: int, alt: string|null, title: string, focalX: int, focalY: int}  $item
     */
    public function img(array $item, ?string $alt, bool $decorative = false): string
    {
        $variant = $this->variant($item, self::BODY_WIDTH);

        if ($variant === null) {
            return '';
        }

        $text = $decorative ? '' : (trim((string) $alt) !== '' ? trim((string) $alt) : (string) ($item['alt'] ?? ''));

        return sprintf('<img src="%s" alt="%s" width="%d" height="%d">', e($variant['url']), e($text), $variant['width'], $variant['height']);
    }
}
