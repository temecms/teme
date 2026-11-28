<?php

/**
 * Deletes the file of an uploads copy Teme stored itself: only a file marked
 * `kept_by_teme`, and only inside the uploads folder on the private disk.
 * A copy named by the operator is read in place and is never deleted here.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Uploads;

use App\Domain\Import\Models\ImportUpload;
use Illuminate\Support\Facades\Storage;

final class KeptUploads
{
    /**
     * Deletes the copy's file when Teme stored it; does nothing otherwise.
     */
    public static function forget(ImportUpload $upload): void
    {
        if (! $upload->kept_by_teme) {
            return;
        }

        $folder = Storage::disk('local')->path('imports/uploads').DIRECTORY_SEPARATOR;

        if (str_starts_with((string) $upload->path, $folder) && is_file($upload->path)) {
            unlink($upload->path);
        }
    }
}
