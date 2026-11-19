<?php

/**
 * The library's credit and licence for each image the editor shows: the
 * featured image draws them under its thumbnail, as readers see the credit
 * under the page's picture.
 * The server renderer's own lookup (CardMedia) stays without it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use Illuminate\Support\Facades\DB;

final class MediaCredits
{
    /**
     * The same media, each item given its credit and licence: the library's
     * text, or null when it has none. One statement for the whole map.
     *
     * @param  array<string, array{variants: array<string, array{path: string, url: string, width: int, height: int, bytes: int}>, width: int, height: int, alt: string|null, title: string, focalX: int, focalY: int}>  $media
     *
     * @return array<string, array{variants: array<string, array{path: string, url: string, width: int, height: int, bytes: int}>, width: int, height: int, alt: string|null, title: string, focalX: int, focalY: int, credit: string|null, licence: string|null}>
     */
    public function onto(array $media): array
    {
        $rows = $media === []
            ? collect()
            : DB::table('media')->whereIn('id', array_keys($media))->get(['id', 'credit', 'licence'])->keyBy('id');

        $credited = [];

        foreach ($media as $id => $item) {
            $credit = $rows[$id]->credit ?? null;
            $licence = $rows[$id]->licence ?? null;
            $credited[$id] = [
                ...$item,
                'credit' => is_string($credit) && trim($credit) !== '' ? $credit : null,
                'licence' => is_string($licence) && trim($licence) !== '' ? $licence : null,
            ];
        }

        return $credited;
    }
}
