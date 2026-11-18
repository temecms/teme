<?php

/**
 * Your items in review: the viewer's pending submissions, newest first — a
 * live article's update as well as a new article in review, since it reads
 * the reviews, not the status. One query on
 * content_reviews_pending_by_submitter. It reads only.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Dashboard\Panels;

use App\Domain\Dashboard\DashboardPanelQuery;
use App\Domain\Support\RelativeTime;
use App\Domain\Types\ContentType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class MyItemsInReview implements DashboardPanelQuery
{
    public function rows(User $viewer, array $types, int $limit): array
    {
        $now = CarbonImmutable::now();

        return DB::table('content_reviews')
            ->join('contents', 'contents.id', '=', 'content_reviews.content_id')
            ->whereNull('content_reviews.decided_at')
            ->where('content_reviews.submitted_by', (int) $viewer->getAuthIdentifier())
            ->whereNull('contents.deleted_at')
            ->whereIn('contents.type', array_map(fn (ContentType $type): string => $type->key, $types))
            ->orderByDesc('content_reviews.submitted_at')
            ->orderByDesc('content_reviews.id')
            ->limit($limit)
            ->get(['contents.id', 'contents.title', 'content_reviews.submitted_at'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'subtitle' => 'Submitted '.RelativeTime::from(CarbonImmutable::parse($row->submitted_at), $now),
                'url' => route('content.edit', ['content' => $row->id], false),
                'at' => CarbonImmutable::parse($row->submitted_at)->toIso8601String(),
            ])->values()->all();
    }
}
