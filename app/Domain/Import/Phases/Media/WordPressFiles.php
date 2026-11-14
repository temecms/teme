<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Media;

use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Convert\Media\ImageTrace;

/**
 * Which file map keys an attachment needs (I-338): the path below the
 * uploads folder of its original, its main file, its edited copies and its
 * sizes, each as ConversionSite::fileKey() makes a key, leaving out any a
 * key already listed reaches by taking a size, then -scaled or -rotated,
 * off, as ImportedMedia::file() and the converter's trace do.
 */
final class WordPressFiles
{
    /**
     * The attachment's keys, the original's first; none for an attachment
     * with no file below the uploads folder. A key that isn't UTF-8, holds
     * a NUL, or passes the map's 500 characters is left out.
     *
     * @param  array<string, mixed>  $item
     *
     * @return list<string>
     */
    public static function keys(array $item): array
    {
        $main = self::relative((string) ($item['file'] ?? ''));

        if ($main === null) {
            return [];
        }

        $folder = str_contains($main, '/') ? substr($main, 0, (int) strrpos($main, '/') + 1) : '';
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $names = [];

        if (is_string($metadata['original_image'] ?? null) && $metadata['original_image'] !== '') {
            $names[] = $folder.basename($metadata['original_image']);
        }

        $names[] = $main;

        foreach ([$item['backup_sizes'] ?? null, $metadata['sizes'] ?? null] as $copies) {
            foreach (is_array($copies) ? $copies : [] as $copy) {
                if (is_array($copy) && is_string($copy['file'] ?? null) && $copy['file'] !== '') {
                    $names[] = $folder.basename(str_replace('\\', '/', $copy['file']));
                }
            }
        }

        $keys = [];

        foreach ($names as $name) {
            $key = ConversionSite::fileKey($name);

            if ($key === '' || str_ends_with($key, '/') || ! mb_check_encoding($key, 'UTF-8') || str_contains($key, "\0") || mb_strlen($key) > 500) {
                continue;
            }

            if (array_intersect(self::reaches($key), $keys) === []) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The keys a lookup tries for a file, its own first: without a
     * -{w}x{h} size, then without -scaled or -rotated as well.
     *
     * @return list<string>
     */
    public static function reaches(string $key): array
    {
        $unsized = (string) preg_replace('/-\d+x\d+(?=\.[a-z0-9]+$)/', '', $key);

        return array_values(array_unique([$key, $unsized, ImageTrace::original($key)]));
    }

    /**
     * _wp_attached_file below the uploads folder: as it is when relative;
     * for an absolute path, which WordPress kept before 2.7, the part after
     * /wp-content/uploads/; null for none.
     */
    public static function relative(string $file): ?string
    {
        $file = str_replace('\\', '/', trim($file));

        if ($file === '') {
            return null;
        }

        if (str_starts_with($file, '/') || preg_match('#^[a-z]:/#i', $file) === 1) {
            $at = strpos($file, '/wp-content/uploads/');

            return $at === false ? null : substr($file, $at + strlen('/wp-content/uploads/'));
        }

        return $file;
    }
}
