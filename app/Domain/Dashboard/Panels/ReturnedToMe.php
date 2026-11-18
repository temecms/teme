<?php

/**
 * Returned to you: the viewer's work a reviewer sent back, with the note,
 * newest return first. Only each item's latest review counts, so an item
 * submitted again since is gone. The candidates are the viewer's returns of
 * the last 30 days (content_reviews_returned_by_submitter); each one's latest
 * review comes from content_reviews_content_submitted. One query. It reads
 * only.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Dashboard\Panels;

use App\Domain\Dashboard\DashboardPanelQuery;
use App\Domain\Types\ContentType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ReturnedToMe implements DashboardPanelQuery
{
    /** How far back a return is still worth showing. */
    private const int DAYS = 30;

    /** How much of the reviewer's note the row shows. */
    private const int NOTE = 120;

    public function rows(User $viewer, array $types, int $limit): array
    {
        $viewerId = (int) $viewer->getAuthIdentifier();
        $keys = array_map(fn (ContentType $type): string => $type->key, $types);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));

        $rows = DB::select(<<<SQL
            SELECT contents.id, contents.title, latest.note, latest.decided_at, deciders.name AS decided_by_name
            FROM (
                SELECT DISTINCT ON (r.content_id) r.content_id, r.decision, r.submitted_by, r.decided_by, r.decided_at, r.note
                FROM content_reviews r
                WHERE r.content_id IN (
                    SELECT content_id FROM content_reviews
                    WHERE submitted_by = ? AND decision = 'returned' AND decided_at >= ?
                )
                ORDER BY r.content_id, r.submitted_at DESC, r.id DESC
            ) latest
            JOIN contents ON contents.id = latest.content_id
            LEFT JOIN users deciders ON deciders.id = latest.decided_by
            WHERE latest.decision = 'returned'
              AND latest.submitted_by = ?
              AND contents.deleted_at IS NULL
              AND contents.type IN ({$placeholders})
            ORDER BY latest.decided_at DESC, contents.id DESC
            LIMIT ?
            SQL, [$viewerId, CarbonImmutable::now()->subDays(self::DAYS), $viewerId, ...$keys, $limit]);

        return array_map(fn (object $row): array => [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'subtitle' => self::note((string) $row->note).' · returned by '.($row->decided_by_name ?? 'a removed account'),
            'url' => route('content.edit', ['content' => $row->id], false),
            'at' => CarbonImmutable::parse($row->decided_at)->toIso8601String(),
        ], $rows);
    }

    /** The note's first 120 characters, right-trimmed, with "…" when it was longer. */
    private static function note(string $note): string
    {
        return mb_strlen($note) > self::NOTE ? rtrim(mb_substr($note, 0, self::NOTE)).'…' : $note;
    }
}
