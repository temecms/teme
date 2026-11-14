<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Panels;

use App\Domain\Dashboard\DashboardPanelQuery;
use App\Domain\Support\RelativeTime;
use App\Domain\Types\ContentType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Your drafts: the viewer's own drafts of the given types, most recently
 * changed first. One query on contents_mine_status_updated. It reads only.
 */
final class MyDrafts implements DashboardPanelQuery
{
    public function rows(User $viewer, array $types, int $limit): array
    {
        $now = CarbonImmutable::now();
        $byKey = [];

        foreach ($types as $type) {
            $byKey[$type->key] = $type;
        }

        return DB::table('contents')
            ->where('author_id', (int) $viewer->getAuthIdentifier())
            ->where('status', 'draft')
            ->whereNull('deleted_at')
            ->whereIn('type', array_map(fn (ContentType $type): string => $type->key, $types))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'title', 'type', 'updated_at'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'subtitle' => 'Edited '.RelativeTime::from(CarbonImmutable::parse($row->updated_at), $now).' · '.($byKey[(string) $row->type]->singular ?? (string) $row->type),
                'url' => route('content.edit', ['content' => $row->id], false),
                'at' => CarbonImmutable::parse($row->updated_at)->toIso8601String(),
            ])->values()->all();
    }
}
