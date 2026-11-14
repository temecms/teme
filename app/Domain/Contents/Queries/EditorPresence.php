<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use Illuminate\Support\Facades\DB;

/**
 * Who else is in an article: the other people with an autosave on it saved
 * within the presence window. An autosave row is presence, because someone
 * with unsaved work is exactly who the other person needs to know about
 * (I-193).
 */
final class EditorPresence
{
    /**
     * The other people in the article, by name.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function othersIn(string $contentId, string $userId): array
    {
        return DB::table('content_autosaves as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.content_id', $contentId)
            ->where('a.user_id', '<>', (int) $userId)
            ->where('a.saved_at', '>=', now()->subSeconds((int) config('editor.presence_window', 120)))
            ->orderBy('u.name')
            ->get(['u.id', 'u.name'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();
    }
}
