<?php

/**
 * Where a plugin's downloads are kept: one folder per plugin code under the
 * application's own storage, never the public site, its exports or its
 * framework cache. A file is removed a day after it was written, by the daily
 * sweep, and a plugin's folder goes with the plugin's data.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Downloads;

use FilesystemIterator;
use Illuminate\Support\Facades\File;

final class DownloadFolders
{
    /** How long a download is kept, in seconds. */
    public const int KEPT_SECONDS = 86_400;

    /** The folder a plugin's downloads are kept in. */
    public static function pathFor(string $code): string
    {
        return storage_path('app/plugin-downloads/'.$code);
    }

    /** Removes a plugin's folder and every download in it. */
    public static function remove(string $code): void
    {
        File::deleteDirectory(self::pathFor($code));
    }

    /**
     * Removes the downloads older than a day in every plugin's folder, and
     * returns how many it removed.
     */
    public static function sweep(): int
    {
        $root = storage_path('app/plugin-downloads');

        if (! is_dir($root)) {
            return 0;
        }

        $removed = 0;

        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $folder) {
            if ($folder->isDir()) {
                $removed += self::sweepFolder($folder->getPathname());
            }
        }

        return $removed;
    }

    /** Removes the downloads older than a day in one folder, and returns how many. */
    public static function sweepFolder(string $folder): int
    {
        if (! is_dir($folder)) {
            return 0;
        }

        $removed = 0;

        foreach (new FilesystemIterator($folder, FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isFile() && $file->getMTime() < time() - self::KEPT_SECONDS && @unlink($file->getPathname())) {
                $removed++;
            }
        }

        return $removed;
    }
}
