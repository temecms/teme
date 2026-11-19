<?php

/**
 * The folders Teme writes to while it runs, which every role mounts
 * (I-378): storage/app, storage/framework, and the page cache's.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment\Health;

final class HealthFolders
{
    /**
     * @return list<string>
     */
    public static function paths(): array
    {
        $paths = [storage_path('app'), storage_path('framework'), (string) config('page_cache.stores.file.path')];

        if (config('page_cache.driver') === 'static') {
            $paths[] = (string) config('page_cache.stores.static.path');
        }

        return $paths;
    }

    /**
     * Each of paths() that can't be written.
     *
     * @return list<string>
     */
    public static function unwritable(): array
    {
        return array_values(array_filter(self::paths(), fn (string $path): bool => ! self::writable($path)));
    }

    /**
     * Whether Teme can write in $path: the folder itself when it exists,
     * or, since Teme makes a missing one on first use, its nearest existing
     * parent. A file where a folder should be can't be written.
     */
    public static function writable(string $path): bool
    {
        $nearest = $path;

        while (! file_exists($nearest)) {
            $parent = dirname($nearest);

            if ($parent === $nearest) {
                return false;
            }

            $nearest = $parent;
        }

        return is_dir($nearest) && is_writable($nearest);
    }
}
