<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The preview links made for one article, for the Share panel: when each was
 * made and by whom, when it expires, whether it still works, and how often
 * it has been opened.
 */
final class PreviewLinkList
{
    /**
     * Every link made for the article, newest first, or null when the viewer
     * may not edit it. A missing or trashed article is a 404.
     *
     * @return list<array{id: string, createdBy: string|null, createdByName: string|null, createdAt: string, expiresAt: string, revokedAt: string|null, lastViewedAt: string|null, viewCount: int, live: bool}>|null
     */
    public function forContent(string $contentId, User $viewer): ?array
    {
        $content = Content::query()->findOrFail($contentId);

        if (! Gate::forUser($viewer)->allows('update', $content)) {
            return null;
        }

        $now = Date::now();
        $iso = fn (mixed $value): ?string => $value === null ? null : Carbon::parse((string) $value)->toIso8601String();

        return DB::table('preview_links')
            ->leftJoin('users', 'users.id', '=', 'preview_links.created_by')
            ->where('preview_links.content_id', $content->id)
            ->orderByDesc('preview_links.created_at')
            ->orderByDesc('preview_links.id')
            ->get(['preview_links.*', 'users.name as created_by_name'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'createdBy' => $row->created_by === null ? null : (string) $row->created_by,
                'createdByName' => $row->created_by_name === null ? null : (string) $row->created_by_name,
                'createdAt' => (string) $iso($row->created_at),
                'expiresAt' => (string) $iso($row->expires_at),
                'revokedAt' => $iso($row->revoked_at),
                'lastViewedAt' => $iso($row->last_viewed_at),
                'viewCount' => (int) $row->view_count,
                'live' => $row->revoked_at === null && Carbon::parse((string) $row->expires_at)->greaterThan($now),
            ])
            ->values()
            ->all();
    }
}
