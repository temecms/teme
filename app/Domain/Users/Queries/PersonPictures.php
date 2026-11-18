<?php

/**
 * Each account's picture as an address made now from the site's own address
 * (rule 9): the image's square thumbnail, or none while the account has no
 * picture, the image is in the trash, or its thumbnail is not made yet.
 *
 * @package Teme
 * @since   0.8.0-alpha.1
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Queries;

use App\Domain\Media\MediaUrls;
use Illuminate\Support\Facades\DB;

final class PersonPictures
{
    /**
     * The picture address of each account that has one, keyed by account ID.
     *
     * @param  list<int>  $userIds
     *
     * @return array<int, string>
     */
    public function for(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $pictures = [];

        foreach (DB::table('users')
            ->join('media', 'media.id', '=', 'users.picture_media_id')
            ->whereIn('users.id', $userIds)
            ->whereNull('media.deleted_at')
            ->where('media.kind', 'image')
            ->get(['users.id', 'media.variants']) as $row) {
            /** @var array<string, array{path: string, width: int, height: int, bytes: int}> $variants */
            $variants = (array) json_decode((string) $row->variants, true, flags: JSON_THROW_ON_ERROR);
            $url = MediaUrls::variant($variants);

            if ($url !== null) {
                $pictures[(int) $row->id] = $url;
            }
        }

        return $pictures;
    }

    /** One account's picture address, or null. */
    public function of(int $userId): ?string
    {
        return $this->for([$userId])[$userId] ?? null;
    }
}
