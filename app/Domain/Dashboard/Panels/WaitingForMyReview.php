<?php

/**
 * Waiting for your review: the pending reviews of the types the viewer may
 * decide on, oldest first, so the longest wait comes first. One query on
 * content_reviews_pending. It reads only.
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

final class WaitingForMyReview implements DashboardPanelQuery
{
    public function rows(User $viewer, array $types, int $limit): array
    {
        $now = CarbonImmutable::now();

        return DB::table('content_reviews')
            ->join('contents', 'contents.id', '=', 'content_reviews.content_id')
            ->leftJoin('users', 'users.id', '=', 'content_reviews.submitted_by')
            ->whereNull('content_reviews.decided_at')
            ->whereNull('contents.deleted_at')
            ->whereIn('contents.type', array_map(fn (ContentType $type): string => $type->key, $types))
            ->orderBy('content_reviews.submitted_at')
            ->orderBy('content_reviews.id')
            ->limit($limit)
            ->get(['contents.id', 'contents.title', 'content_reviews.submitted_at', 'users.name'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'subtitle' => 'Submitted by '.($row->name ?? 'a removed account').' · '.RelativeTime::from(CarbonImmutable::parse($row->submitted_at), $now),
                'url' => route('content.edit', ['content' => $row->id], false),
                'at' => CarbonImmutable::parse($row->submitted_at)->toIso8601String(),
            ])->values()->all();
    }
}
