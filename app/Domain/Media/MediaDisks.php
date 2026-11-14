<?php

declare(strict_types=1);

namespace App\Domain\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The two media disks, by role (docs/architecture/media.md, "Storage"). An
 * image's original is private; every other original, and every variant, is
 * public.
 */
final class MediaDisks
{
    public static function public(): Filesystem
    {
        return Storage::disk((string) config('media.disk'));
    }

    public static function originals(): Filesystem
    {
        return Storage::disk((string) config('media.originals_disk'));
    }

    public static function forOriginal(string $kind): Filesystem
    {
        return $kind === 'image' ? self::originals() : self::public();
    }
}
