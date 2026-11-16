<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * The files one media row names (docs/architecture/media.md): its original,
 * on the originals role for an image and the public role otherwise, with
 * its recorded SHA-256, and every variant, on the public role. Read from
 * the row, never from a folder's listing, so a backup and a move see the
 * same files.
 */
final class MediaFiles
{
    /**
     * Each file the row names, the original first.
     *
     * @param  object{kind: string, original_path: string, size_bytes: int|string, sha256: string|null, variants: string|array<string, mixed>|null}  $row
     *
     * @return list<array{role: 'public'|'originals', path: string, bytes: int, sha256: string|null}>
     */
    public static function of(object $row): array
    {
        $files = [[
            'role' => (string) $row->kind === 'image' ? 'originals' : 'public',
            'path' => (string) $row->original_path,
            'bytes' => (int) $row->size_bytes,
            'sha256' => $row->sha256 === null || $row->sha256 === '' ? null : (string) $row->sha256,
        ]];

        $variants = is_string($row->variants) ? json_decode($row->variants, true, flags: JSON_THROW_ON_ERROR) : $row->variants;

        foreach (is_array($variants) ? $variants : [] as $variant) {
            if (is_array($variant) && is_string($variant['path'] ?? null)) {
                $files[] = ['role' => 'public', 'path' => $variant['path'], 'bytes' => (int) ($variant['bytes'] ?? 0), 'sha256' => null];
            }
        }

        return $files;
    }
}
