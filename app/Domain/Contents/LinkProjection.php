<?php

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Cards\References;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use Illuminate\Support\Facades\DB;

/**
 * Keeps content_links equal to what an item's revisions say, as
 * TermProjection does for terms: the articles the working revision links to
 * while the item is live, and the published revision's while it is live and
 * published. Links are derived, never authored, so this can always be rerun.
 */
final class LinkProjection
{
    /**
     * Brings one item's links into line with its revisions, writing only the
     * difference. Call in every content Operation's perform(), right after
     * MediaProjection::sync().
     *
     * @return array{added: int, removed: int}
     */
    public static function sync(Content $content): array
    {
        $desired = self::desired($content);
        $current = [];

        foreach (DB::table('content_links')->where('from_content_id', $content->id)->get(['scope', 'to_content_id']) as $row) {
            $current["{$row->scope}|{$row->to_content_id}"] = true;
        }

        $removed = 0;

        foreach (array_keys(array_diff_key($current, $desired)) as $key) {
            [$scope, $to] = explode('|', $key, 2);
            $removed += DB::table('content_links')->where(['from_content_id' => $content->id, 'scope' => $scope, 'to_content_id' => $to])->delete();
        }

        $rows = [];

        foreach (array_keys(array_diff_key($desired, $current)) as $key) {
            [$scope, $to] = explode('|', $key, 2);
            $rows[] = ['from_content_id' => $content->id, 'to_content_id' => $to, 'scope' => $scope];
        }

        if ($rows !== []) {
            DB::table('content_links')->insert($rows);
        }

        return ['added' => count($rows), 'removed' => $removed];
    }

    /**
     * @return array<string, true> "scope|to content ID" => true
     */
    private static function desired(Content $content): array
    {
        if ($content->trashed()) {
            return [];
        }

        $scopes = ['working' => $content->working_revision_id];

        if ($content->status === 'published' && $content->published_revision_id !== null) {
            $scopes['published'] = $content->published_revision_id;
        }

        $documents = ContentRevision::query()->whereIn('id', array_values(array_filter($scopes)))
            ->pluck('document', 'id');
        $wanted = [];

        foreach ($scopes as $scope => $revisionId) {
            $raw = $documents[$revisionId] ?? [];
            $document = is_string($raw) ? (array) json_decode($raw, true) : (array) $raw;

            foreach (References::contentIds($document) as $to) {
                // An item never records a link to itself.
                if ($to !== $content->id) {
                    $wanted["{$scope}|{$to}"] = $to;
                }
            }
        }

        if ($wanted === []) {
            return [];
        }

        // A target deleted for good since the revision was written drops out.
        $existing = DB::table('contents')->whereIn('id', array_unique(array_values($wanted)))->pluck('id')->flip();

        return array_map(fn (): bool => true, array_filter($wanted, fn (string $id): bool => $existing->has($id)));
    }
}
