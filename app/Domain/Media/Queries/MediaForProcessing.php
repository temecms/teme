<?php

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use Illuminate\Support\Facades\DB;

/**
 * What the variant maker needs to know about one image.
 */
final class MediaForProcessing
{
    /**
     * The image's current file version, the path of its original, its stored
     * name, focal point and width — or null if there is no such image.
     *
     * @return array{id: string, fileVersion: int, originalPath: string, name: string, focalX: int, focalY: int, width: int}|null
     */
    public static function find(string $id): ?array
    {
        $row = DB::table('media')->where('id', $id)->where('kind', 'image')
            ->first(['id', 'file_version', 'original_path', 'name', 'focal_x', 'focal_y', 'width']);

        return $row === null ? null : [
            'id' => (string) $row->id,
            'fileVersion' => (int) $row->file_version,
            'originalPath' => (string) $row->original_path,
            'name' => (string) $row->name,
            'focalX' => (int) $row->focal_x,
            'focalY' => (int) $row->focal_y,
            'width' => (int) $row->width,
        ];
    }
}
