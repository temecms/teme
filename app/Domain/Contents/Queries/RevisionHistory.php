<?php

/**
 * An article's revisions, newest first: who made each one, what kind it is,
 * and how many words it added or took away. It pages by keyset (I-140), with
 * no count and no offset.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Contents\Models\Content;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Queries\Cursor;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RevisionHistory
{
    public const int PAGE = 25;

    /**
     * One page of the article's history, older than the cursor when one is
     * given, or null when the viewer may not edit the article. A missing or
     * trashed article is a 404.
     */
    public static function for(string $contentId, User $viewer, ?string $cursor = null): ?RevisionPage
    {
        $content = Content::query()->findOrFail($contentId);

        if (! Gate::forUser($viewer)->allows('update', $content)) {
            return null;
        }

        // The word change is worked out over all of the article's revisions,
        // inside the subquery, and the keyset applies outside it, so the
        // oldest row on a page still sees its predecessor on the next.
        $revisions = DB::table('content_revisions')
            ->where('content_id', $content->id)
            ->select(['id', 'kind', 'origin', 'word_count', 'author_id', 'via', 'created_at'])
            ->selectRaw('word_count - lag(word_count) OVER (ORDER BY created_at, id) AS word_delta');

        $query = DB::query()->fromSub($revisions, 'r');
        $after = Cursor::decode($cursor, 2);

        if ($after !== null) {
            $query->whereRaw('(r.created_at, r.id) < (?::timestamptz, ?)', [$after[0], $after[1]]);
        }

        $rows = $query->orderByDesc('r.created_at')->orderByDesc('r.id')->limit(self::PAGE + 1)->get();
        $hasOlder = $rows->count() > self::PAGE;
        $rows = $rows->take(self::PAGE);

        $authorIds = $rows->pluck('author_id')->filter()->unique()->values()->all();
        $authors = $authorIds === [] ? collect() : DB::table('users')->whereIn('id', $authorIds)->pluck('name', 'id');
        $viaLabels = self::viaLabels($rows->pluck('via')->filter()->unique()->values()->all());
        $last = $rows->last();

        return new RevisionPage(
            rows: $rows->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'kind' => (string) $row->kind,
                'origin' => (string) $row->origin,
                'wordCount' => (int) $row->word_count,
                'wordDelta' => $row->word_delta === null ? null : (int) $row->word_delta,
                'author' => $row->author_id === null ? null : ($authors[$row->author_id] ?? null),
                'via' => $row->via === null || $row->via === '' ? null : __('through :plugin', ['plugin' => $viaLabels[(string) $row->via] ?? (string) $row->via]),
                'createdAt' => Carbon::parse((string) $row->created_at)->toIso8601String(),
                'isPublished' => $row->id === $content->published_revision_id,
                'isWorking' => $row->id === $content->working_revision_id,
            ])->values()->all(),
            older: $hasOlder && $last !== null ? Cursor::encode([(string) $last->created_at, (string) $last->id]) : null,
        );
    }

    /**
     * Plugin codes to the name people see, from the registry; a missing
     * plugin keeps its code.
     *
     * @param  list<mixed>  $codes
     *
     * @return array<string, string>
     */
    private static function viaLabels(array $codes): array
    {
        $labels = [];

        foreach ($codes as $code) {
            if (! is_string($code) || $code === '') {
                continue;
            }

            $labels[$code] = $code;
        }

        if ($labels === []) {
            return [];
        }

        foreach (app(PluginRegistry::class)->all() as $plugin) {
            if (isset($labels[$plugin->code])) {
                $label = $plugin->manifest?->label();
                $labels[$plugin->code] = is_string($label) && $label !== '' ? $label : $plugin->code;
            }
        }

        return $labels;
    }
}
