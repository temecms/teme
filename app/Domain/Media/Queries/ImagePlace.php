<?php

/**
 * Where one live image sits among the library's live images, newest first,
 * as Edit image's "2 of 20" and its previous and next buttons name it.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use App\Domain\Media\MediaCounts;
use Illuminate\Support\Facades\DB;

final class ImagePlace
{
    /**
     * Returns the image's number among the live images, newest first, their
     * total, and the IDs of the images either side of it; null when the ID
     * names no live image.
     *
     * @return array{number: int, total: int, previous: string|null, next: string|null}|null
     */
    public function of(string $id): ?array
    {
        $image = DB::table('media')->where('id', $id)->where('kind', 'image')->whereNull('deleted_at')->first(['id', 'created_at']);

        if ($image === null) {
            return null;
        }

        $live = fn () => DB::table('media')->where('kind', 'image')->whereNull('deleted_at');
        $key = [$image->created_at, $image->id];

        $previous = $live()->whereRaw('(created_at, id) > (?, ?)', $key)->orderBy('created_at')->orderBy('id')->value('id');
        $next = $live()->whereRaw('(created_at, id) < (?, ?)', $key)->orderByDesc('created_at')->orderByDesc('id')->value('id');
        $newer = $live()->whereRaw('(created_at, id) > (?, ?)', $key)->count();

        return [
            'number' => $newer + 1,
            'total' => MediaCounts::all()['image']['live'] ?? $newer + 1,
            'previous' => $previous === null ? null : (string) $previous,
            'next' => $next === null ? null : (string) $next,
        ];
    }
}
