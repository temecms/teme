<?php

/**
 * Public URLs for a media item's files, built from its recorded paths and the
 * disk's configured URL at the moment of asking — never stored (rule 9). Given
 * the item's ID, a plugin's image service may give the address instead
 * (docs/guides/developers/plugins/image-service.md).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Plugins\Images\ImageServices;

final class MediaUrls
{
    /**
     * The URL of the variant named $key, or null when there is none.
     *
     * @param  array<string, array{path: string, width: int, height: int, bytes: int}>  $variants
     * @param  string|null  $mediaId  the item's ID, so the image service is asked; null for the disk's address alone
     */
    public static function variant(array $variants, string $key = 'thumb', ?string $mediaId = null): ?string
    {
        return isset($variants[$key]['path']) ? self::url($mediaId, $key, $variants[$key]['path']) : null;
    }

    /**
     * The widest variant's URL, for previews.
     *
     * @param  array<string, array{path: string, width: int, height: int, bytes: int}>  $variants
     * @param  string|null  $mediaId  the item's ID, so the image service is asked; null for the disk's address alone
     */
    public static function largest(array $variants, ?string $mediaId = null): ?string
    {
        $widths = array_filter($variants, fn (string $key): bool => str_starts_with($key, 'w'), ARRAY_FILTER_USE_KEY);

        if ($widths === []) {
            return null;
        }

        uasort($widths, fn (array $a, array $b): int => $b['width'] <=> $a['width']);

        return self::url($mediaId, (string) array_key_first($widths), (string) reset($widths)['path']);
    }

    /**
     * The URL of the narrowest width variant at least $width wide, else the
     * widest; null with no width variant yet.
     *
     * @since 1.4.0
     *
     * @param  array<string, array{path: string, width: int, height: int, bytes: int}>  $variants
     * @param  string|null  $mediaId  the item's ID, so the image service is asked; null for the disk's address alone
     */
    public static function nearest(array $variants, int $width, ?string $mediaId = null): ?string
    {
        $widths = array_filter($variants, fn (string $key): bool => str_starts_with($key, 'w'), ARRAY_FILTER_USE_KEY);

        if ($widths === []) {
            return null;
        }

        uasort($widths, fn (array $a, array $b): int => $a['width'] <=> $b['width']);
        $wide = array_filter($widths, fn (array $variant): bool => $variant['width'] >= $width);
        $chosen = $wide === [] ? $widths : $wide;
        $key = $wide === [] ? (string) array_key_last($widths) : (string) array_key_first($wide);

        return self::url($mediaId, $key, (string) $chosen[$key]['path']);
    }

    /** The image service's address for the variant, given the item's ID; the disk's otherwise. */
    private static function url(?string $mediaId, string $key, string $path): string
    {
        return $mediaId === null ? MediaDisks::public()->url($path) : app(ImageServices::class)->address($mediaId, $key, $path);
    }
}
