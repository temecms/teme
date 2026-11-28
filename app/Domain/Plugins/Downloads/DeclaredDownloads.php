<?php

/**
 * The largest file a plugin's downloads may hold, from its manifest's
 * `contributes.downloads.maxBytes`: 16 MB when it declares none, and at most
 * 128 MB a file, with the plugin's whole folder at most 512 MB.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Downloads;

use App\Domain\Plugins\Doors\PluginHttp;
use App\Domain\Plugins\Manifest;

final class DeclaredDownloads
{
    /** The default, in bytes, when the manifest declares none. */
    public const int DEFAULT_BYTES = PluginHttp::LONG_ANSWER_BYTES;

    /** The smallest a declared limit may be, in bytes. */
    public const int LEAST_BYTES = 1_048_576;

    /**
     * The file size limit the manifest declares, or null when it declares none.
     */
    public static function declared(Manifest $manifest): ?int
    {
        $downloads = $manifest->contributes()['downloads'] ?? null;
        $bytes = is_array($downloads) ? ($downloads['maxBytes'] ?? null) : null;

        return is_int($bytes) ? $bytes : null;
    }

    /**
     * The file size limit the manifest declares, or the default.
     */
    public static function of(Manifest $manifest): int
    {
        $downloads = $manifest->contributes()['downloads'] ?? null;
        $bytes = is_array($downloads) ? ($downloads['maxBytes'] ?? null) : null;

        return is_int($bytes) ? $bytes : self::DEFAULT_BYTES;
    }

    /**
     * Returns why the declared downloads can't be used, or nothing when they can.
     *
     * @return list<string>
     */
    public static function errors(mixed $downloads): array
    {
        $bytes = is_array($downloads) ? ($downloads['maxBytes'] ?? null) : null;

        if (! is_array($downloads) || ! is_int($bytes) || $bytes < self::LEAST_BYTES || $bytes > PluginHttp::MOST_DOWNLOAD_BYTES) {
            return [__('A plugin’s downloads hold files of 1 MB to 128 MB, given as maxBytes.')];
        }

        return [];
    }
}
