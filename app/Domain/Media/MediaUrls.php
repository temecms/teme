<?php

/**
 * Public URLs for a media item's files, built from its recorded paths and the
 * disk's configured URL at the moment of asking — never stored (rule 9).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media;

final class MediaUrls
{
    /**
     * @param  array<string, array{path: string, width: int, height: int, bytes: int}>  $variants
     */
    public static function variant(array $variants, string $key = 'thumb'): ?string
    {
        return isset($variants[$key]['path']) ? MediaDisks::public()->url($variants[$key]['path']) : null;
    }

    /**
     * The widest variant's URL, for previews.
     *
     * @param  array<string, array{path: string, width: int, height: int, bytes: int}>  $variants
     */
    public static function largest(array $variants): ?string
    {
        $widths = array_filter($variants, fn (string $key): bool => str_starts_with($key, 'w'), ARRAY_FILTER_USE_KEY);

        if ($widths === []) {
            return null;
        }

        uasort($widths, fn (array $a, array $b): int => $b['width'] <=> $a['width']);

        return MediaDisks::public()->url((string) reset($widths)['path']);
    }
}
