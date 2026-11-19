<?php

/**
 * You edited recently: up to five articles, each once, newest first, from
 * the viewer's latest 50 revisions (content_revisions_author_created). That
 * keeps it one bounded query on an index; the price of the bound is that
 * someone who saved one article fifty times in a row sees only that one.
 * It reads only.
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

final class MyRecentEdits implements DashboardPanelQuery
{
    /** How many of the viewer's latest revisions are looked through. */
    private const int WINDOW = 50;

    /** What each kind of revision is called, as the history names them. */
    private const array KINDS = [
        'save' => 'Saved',
        'checkpoint' => 'Checkpoint',
        'publish' => 'Published',
        'restore' => 'Restored',
        'import' => 'Imported',
        'ai' => 'Assistant',
    ];

    public function rows(User $viewer, array $types, int $limit): array
    {
        $keys = array_map(fn (ContentType $type): string => $type->key, $types);
        $now = CarbonImmutable::now();

        $rows = DB::select(
            'SELECT contents.id, contents.title, latest.kind, latest.created_at
             FROM (
                 SELECT DISTINCT ON (mine.content_id) mine.content_id, mine.kind, mine.created_at, mine.id
                 FROM (
                     SELECT content_id, kind, created_at, id FROM content_revisions
                     WHERE author_id = ?
                     ORDER BY created_at DESC, id DESC
                     LIMIT ?
                 ) mine
                 ORDER BY mine.content_id, mine.created_at DESC, mine.id DESC
             ) latest
             JOIN contents ON contents.id = latest.content_id
             WHERE contents.deleted_at IS NULL AND contents.type IN ('.implode(', ', array_fill(0, count($keys), '?')).')
             ORDER BY latest.created_at DESC, latest.id DESC
             LIMIT ?',
            [(int) $viewer->getAuthIdentifier(), self::WINDOW, ...$keys, $limit],
        );

        return array_map(fn (object $row): array => [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'subtitle' => (self::KINDS[(string) $row->kind] ?? ucfirst((string) $row->kind)).' '.RelativeTime::from(CarbonImmutable::parse($row->created_at), $now),
            'url' => route('content.edit', ['content' => $row->id], false),
            'at' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
        ], $rows);
    }
}
