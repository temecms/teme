<?php

/**
 * The content item a file was uploaded for (I-553), as the media drawer's
 * line and the library's filter name it: its title, its status, and whether
 * it is in the trash.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use Illuminate\Support\Facades\DB;

final class UploadedForItem
{
    /** A content item's ID: 26 lower-case letters and digits. */
    private const string ID = '/^[0-9a-hjkmnp-tv-z]{26}$/';

    /**
     * Returns the item with this ID, in the trash or not, or null when no
     * item has it or it isn't an item's ID.
     *
     * @return array{id: string, title: string, status: string, trashed: bool}|null
     */
    public function find(?string $contentId): ?array
    {
        if ($contentId === null || preg_match(self::ID, $contentId) !== 1) {
            return null;
        }

        $row = DB::table('contents')->where('id', $contentId)->first(['id', 'title', 'status', 'deleted_at']);

        return $row === null ? null : [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'status' => (string) $row->status,
            'trashed' => $row->deleted_at !== null,
        ];
    }
}
