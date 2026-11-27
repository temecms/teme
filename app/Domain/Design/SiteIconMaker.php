<?php

/**
 * Makes the PNG site-icon sizes from a chosen media image: 32, 180, 192 and
 * 512 square crops, stored as media variants keyed icon-32 and so on. SVG
 * images are left alone and linked as-is (issue 388).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Design;

use App\Domain\Media\MediaDisks;
use App\Domain\Media\Models\Media;
use App\Domain\Media\StorageKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

final class SiteIconMaker
{
    /** @var list<int> */
    public const array SIZES = [32, 180, 192, 512];

    /**
     * Ensures the image has every icon PNG variant, or a public copy of an
     * SVG. Returns the variants map after writing, or null when the file
     * can't be read.
     *
     * @return array<string, array{path: string, width: int, height: int, bytes: int}>|null
     */
    public function ensure(Media $media): ?array
    {
        if (str_contains(strtolower($media->mime_type), 'svg')) {
            return $this->ensureSvg($media);
        }

        $variants = (array) $media->variants;
        $missing = array_values(array_filter(
            self::SIZES,
            fn (int $size): bool => ! isset($variants['icon-'.$size]['path']),
        ));

        if ($missing === []) {
            return $variants;
        }

        $made = $this->make($media, $missing);

        if ($made === null) {
            return null;
        }

        $variants = [...$variants, ...$made];
        DB::table('media')->where('id', $media->id)->update(['variants' => json_encode($variants)]);
        $media->forceFill(['variants' => $variants]);

        return $variants;
    }

    /**
     * @return array<string, array{path: string, width: int, height: int, bytes: int}>|null
     */
    private function ensureSvg(Media $media): ?array
    {
        $variants = (array) $media->variants;

        if (isset($variants['icon-svg']['path'])) {
            return $variants;
        }

        try {
            $bytes = MediaDisks::forOriginal($media->kind)->get($media->original_path);
            $path = StorageKeys::variant($media->id, $media->file_version, $media->name, 'icon-svg');
            MediaDisks::public()->put($path, $bytes);
            $variants['icon-svg'] = ['path' => $path, 'width' => 0, 'height' => 0, 'bytes' => strlen($bytes)];
            DB::table('media')->where('id', $media->id)->update(['variants' => json_encode($variants)]);
            $media->forceFill(['variants' => $variants]);

            return $variants;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<int>  $sizes
     *
     * @return array<string, array{path: string, width: int, height: int, bytes: int}>|null
     */
    private function make(Media $media, array $sizes): ?array
    {
        $disk = MediaDisks::forOriginal($media->kind);
        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-icons-'.Str::random(12);
        mkdir($work, 0777, true);

        try {
            $source = $work.'/source';
            file_put_contents($source, $disk->get($media->original_path));
            $manager = new ImageManager(
                (string) config('media.image_driver', 'gd') === 'imagick' ? ImagickDriver::class : GdDriver::class,
                autoOrientation: true,
                decodeAnimation: true,
                strip: true,
            );
            $image = $manager->decodePath($source);

            if ($image->isAnimated()) {
                $image->removeAnimation();
            }

            $made = [];

            foreach ($sizes as $size) {
                $square = (clone $image)->cover($size, $size);
                $file = $work.'/icon-'.$size.'.png';
                file_put_contents($file, (string) $square->encodeUsingFormat(Format::PNG));
                $path = StorageKeys::variant($media->id, $media->file_version, $media->name, 'icon-'.$size);
                MediaDisks::public()->put($path, (string) file_get_contents($file));
                $made['icon-'.$size] = [
                    'path' => $path,
                    'width' => $size,
                    'height' => $size,
                    'bytes' => (int) filesize($file),
                ];
            }

            return $made;
        } catch (Throwable) {
            return null;
        } finally {
            foreach (glob($work.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($work);
        }
    }
}
