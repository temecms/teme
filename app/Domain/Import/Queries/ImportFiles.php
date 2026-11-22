<?php

/**
 * What Tools › Import reads about uploaded export files: a file’s progress
 * for the upload form, and nothing that writes.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Queries;

use App\Domain\Import\Models\ImportFile;

final readonly class ImportFiles
{
    /**
     * Returns one file as the upload form needs it, or null when it isn’t there.
     *
     * @return array{id: string, name: string, sizeBytes: int, receivedBytes: int, state: string, refused: string|null, sourceId: string|null}|null
     */
    public function status(string $fileId): ?array
    {
        $file = ImportFile::query()->whereKey($fileId)->whereNull('deleted_at')->first();

        if ($file === null) {
            return null;
        }

        return [
            'id' => (string) $file->id,
            'name' => $file->name,
            'sizeBytes' => (int) $file->size_bytes,
            'receivedBytes' => (int) $file->received_bytes,
            'state' => $file->state,
            'refused' => $file->refused,
            'sourceId' => $file->source_id === null ? null : (string) $file->source_id,
        ];
    }

    /**
     * Returns the export file linked to a source, if any, for the sites list’s subtitle.
     *
     * @return array{uploadedAt: string}|null
     */
    public function forSource(string $sourceId): ?array
    {
        $file = ImportFile::query()
            ->where('source_id', $sourceId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first();

        if ($file === null) {
            return null;
        }

        return [
            'uploadedAt' => $file->created_at->utc()->toIso8601String(),
        ];
    }
}
