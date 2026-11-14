<?php

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use App\Domain\Media\MediaDisks;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Where an item's original file is, for the admin's download.
 */
final class MediaFileLocation
{
    /**
     * The disk and path of the item's original, and the name to download it
     * under — its stored name and extension — or null if there is no such
     * item.
     *
     * @return array{disk: FilesystemAdapter, path: string, downloadName: string}|null
     */
    public function original(string $id): ?array
    {
        $row = DB::table('media')->where('id', $id)->first(['kind', 'original_path', 'name', 'extension']);

        if ($row === null) {
            return null;
        }

        $disk = MediaDisks::forOriginal((string) $row->kind);

        if (! $disk instanceof FilesystemAdapter) {
            // Every disk Laravel resolves is a FilesystemAdapter unless a
            // custom driver registers something else — not the case here.
            throw new LogicException('Expected a FilesystemAdapter for the download.');
        }

        return [
            'disk' => $disk,
            'path' => (string) $row->original_path,
            'downloadName' => "{$row->name}.{$row->extension}",
        ];
    }
}
