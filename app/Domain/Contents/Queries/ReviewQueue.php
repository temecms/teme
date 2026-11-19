<?php

/**
 * The pending reviews of a page of content items, read from content_reviews —
 * never from the status, because a live article's update in review stays
 * published. One query, the same on every page whether it holds a review or
 * not (as B-173 holds schedules). It reads only.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ReviewQueue
{
    /**
     * Each listed item's pending review, keyed by item: who submitted it and
     * when, and whether the viewer may decide on it — ContentPolicy::publish,
     * from the abilities the caller read for each type on the page. Items
     * with none are left out.
     *
     * @param  list<string>  $contentIds
     * @param  array<string, ContentAbilities>  $abilities  the viewer's, by content type
     *
     * @return array<string, array{id: string, submittedBy: string|null, submittedAt: string, canDecide: bool}>
     */
    public static function pendingFor(array $contentIds, int $viewerId, array $abilities): array
    {
        if ($contentIds === []) {
            return [];
        }

        $rows = DB::table('content_reviews')
            ->join('contents', 'contents.id', '=', 'content_reviews.content_id')
            ->leftJoin('users', 'users.id', '=', 'content_reviews.submitted_by')
            ->whereIn('content_reviews.content_id', $contentIds)
            ->whereNull('content_reviews.decided_at')
            ->get(['content_reviews.id', 'content_reviews.content_id', 'content_reviews.submitted_at', 'users.name', 'contents.type', 'contents.author_id']);

        $pending = [];

        foreach ($rows as $row) {
            $authorId = $row->author_id === null ? null : (int) $row->author_id;

            $pending[(string) $row->content_id] = [
                'id' => (string) $row->id,
                'submittedBy' => $row->name === null ? null : (string) $row->name,
                'submittedAt' => CarbonImmutable::parse($row->submitted_at)->toIso8601String(),
                'canDecide' => ($abilities[(string) $row->type] ?? null)?->canPublish($authorId, $viewerId) ?? false,
            ];
        }

        return $pending;
    }
}
