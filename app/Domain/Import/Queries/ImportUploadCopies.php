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
     * @return array{name: string, sizeBytes: int, kind: string}|null
     */
    public function forSource(string $sourceId): ?array
    {
        $upload = ImportUpload::query()->where('source_id', $sourceId)->where('state', 'ready')->first();

        if ($upload === null) {
            return null;
        }

        return [
            'name' => (string) $upload->name,
            'sizeBytes' => (int) $upload->size_bytes,
            'kind' => (string) $upload->kind,
        ];
    }
}
