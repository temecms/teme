<?php

/**
 * Reads a site's uploads copy for its import screen: the copy's name and size,
 * and how many pictures the import has brought from it and from the old site.
 * Null when the site has no ready copy.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Queries;

use App\Domain\Import\Models\ImportUpload;

final readonly class ImportUploadCopies
{
    /**
     * The site's ready copy as the screen shows it, or null when it has none.
     *
     * @return array{id: string, keptByTeme: bool, leftOut: array{files: int, folders: array<string, int>}|null, name: string, sizeBytes: int, kind: string}|null
     */
    public function forSource(string $sourceId): ?array
    {
        $upload = ImportUpload::query()->where('source_id', $sourceId)->where('state', 'ready')->first();

        if ($upload === null) {
            return null;
        }

        return [
            'id' => (string) $upload->id,
            'keptByTeme' => (bool) $upload->kept_by_teme,
            'leftOut' => self::leftOut($upload->left_out),
            'name' => (string) $upload->name,
            'sizeBytes' => (int) $upload->size_bytes,
            'kind' => (string) $upload->kind,
        ];
    }

    /**
     * What the copy left out, as the report and the panel show it, or null when it wasn't counted.
     *
     * @param  array<string, mixed>|null  $stored
     *
     * @return array{files: int, folders: array<string, int>}|null
     */
    private static function leftOut(?array $stored): ?array
    {
        if ($stored === null) {
            return null;
        }

        return [
            'files' => (int) ($stored['files'] ?? 0),
            'folders' => array_map(fn (mixed $count): int => (int) $count, (array) ($stored['folders'] ?? [])),
        ];
    }
}
