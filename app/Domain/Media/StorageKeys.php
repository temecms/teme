<?php

/**
 * Where a media item's files live on their disk: under the item's ID and file
 * version, so a key is unique without looking at storage and a replaced file
 * never shares a URL with its predecessor.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media;

use Illuminate\Support\Str;

final class StorageKeys
{
    /**
     * The uploaded name, without its extension, as a slug of at most 80
     * characters — kept in the key for search engines.
     */
    public static function name(string $originalFilename): string
    {
        $slug = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME));

        return mb_substr($slug === '' ? 'file' : $slug, 0, 80);
    }

    public static function original(string $id, int $version, string $name, string $extension): string
    {
        return "{$id}/v{$version}/{$name}.{$extension}";
    }

    public static function variant(string $id, int $version, string $name, string $key): string
    {
        return "{$id}/v{$version}/{$name}-{$key}.".MediaVariants::extensionOf($key);
    }
}
