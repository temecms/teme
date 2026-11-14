<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who wrote a revision, and when: what the editor tells someone whose save
 * was refused because the article changed under them.
 */
final class RevisionAuthor
{
    /**
     * The author's name and the time as ISO 8601, or null when there is no
     * such revision.
     *
     * @return array{name: string, at: string}|null
     */
    public static function of(?string $revisionId): ?array
    {
        if ($revisionId === null) {
            return null;
        }

        $row = DB::table('content_revisions')
            ->leftJoin('users', 'users.id', '=', 'content_revisions.author_id')
            ->where('content_revisions.id', $revisionId)
            ->first(['users.name', 'content_revisions.created_at']);

        if ($row === null) {
            return null;
        }

        $at = $row->created_at instanceof DateTimeInterface ? Carbon::instance($row->created_at) : Carbon::parse((string) $row->created_at);

        return ['name' => (string) ($row->name ?? 'Someone'), 'at' => $at->toIso8601String()];
    }
}
