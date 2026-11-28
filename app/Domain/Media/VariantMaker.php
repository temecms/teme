<?php

/**
 * The image work behind every variant: copies an original to a temporary
 * file, has the ImageEngine make the registered set, and writes the results
 * to the public disk. It writes no database state; its caller records the
 * result through RecordMediaVariants.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Media\Images\ImageEngine;
use App\Domain\Media\Jobs\DeleteMediaFiles;
use App\Domain\Media\Queries\MediaForProcessing;
use App\Domain\Media\Queries\ShareImageUse;
use App\Domain\Media\Queries\SiteIconUse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class VariantMaker
{
    public function __construct(private readonly ImageEngine $engine) {}

    /**
     * @return array<string, array{path: string, width: int, height: int, bytes: int}>|null
     */
    public function make(string $mediaId, int $fileVersion): ?array
    {
        $item = MediaForProcessing::find($mediaId);

        if ($item === null || $item['fileVersion'] !== $fileVersion) {
            return null;
        }

        // A large image in GD needs width × height × 4 bytes, several times over.
        ini_set('memory_limit', '1024M');

        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-variants-'.Str::random(12);
        File::ensureDirectoryExists($work);
        $source = $work.DIRECTORY_SEPARATOR.'original';

        try {
            $in = MediaDisks::originals()->readStream($item['originalPath']);
            $out = fopen($source, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($out);

            if (is_resource($in)) {
                fclose($in);
            }

            $extra = [
                ...(ShareImageUse::wanted($item['id']) ? MediaVariants::SHARE : []),
                ...(SiteIconUse::wanted($item['id']) ? MediaVariants::ICONS : []),
            ];
            $made = $this->engine->variants($source, MediaVariants::widthsFor($item['width']), MediaVariants::THUMBNAIL, $item['focalX'], $item['focalY'], $work, $extra);
            $variants = [];

            foreach ($made as $key => $variant) {
                $path = StorageKeys::variant($item['id'], $fileVersion, $item['name'], $key);
                MediaDisks::public()->put($path, (string) file_get_contents($variant['file']));
                $variants[$key] = ['path' => $path, 'width' => $variant['width'], 'height' => $variant['height'], 'bytes' => $variant['bytes']];
            }

            return $variants;
        } finally {
            // A local temporary folder this method made, emptied by the names
            // the engine writes (<key>.webp, or <key>.png for an icon): nothing here lists a folder.
            $keys = ['thumb', ...array_keys(MediaVariants::SHARE), ...array_keys(MediaVariants::ICONS), ...array_map(fn (int $width): string => "w{$width}", MediaVariants::widthsFor($item['width']))];

            foreach ([$source, ...array_map(fn (string $key): string => $work.DIRECTORY_SEPARATOR.$key.'.'.MediaVariants::extensionOf($key), $keys)] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            @rmdir($work);
        }
    }

    /**
     * Deletes variants a change no longer needs, through DeleteMediaFiles,
     * which waits while a backup is reading them (I-358).
     *
     * @param  list<string>  $paths  paths on the public disk
     */
    public function discard(array $paths): void
    {
        if ($paths !== []) {
            DeleteMediaFiles::dispatch(['public' => $paths]);
        }
    }
}
