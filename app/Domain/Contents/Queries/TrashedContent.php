<?php

/**
 * Finds trashed content for the daily purge.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Tree\SubtreeJobKind;
use Carbon\CarbonInterface;

final class TrashedContent
{
    /**
     * The IDs of up to $limit items trashed before $cutoff, oldest first,
     * read from the trash index; an item whose branch is still moving waits
     * for the next day (I-3363).
     *
     * @return list<string>
     */
    public function olderThan(CarbonInterface $cutoff, int $limit): array
    {
        /** @var list<string> */
        return Content::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->whereNotExists(fn ($moving) => $moving->fromSub(SubtreeJobKind::moving(), 'm')->whereColumn('m.run_id', 'contents.id'))
            ->orderBy('deleted_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }
}
