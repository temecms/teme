<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use Illuminate\Support\Facades\DB;

/**
 * What an action needs to know about each item before calling its
 * Operation: its type and status, the current working revision and that
 * revision's terms.
 */
final class ContentPointers
{
    /**
     * For each ID, its type, its status, its working revision and that
     * revision's term list, in one query. Trashed items are included; unknown
     * IDs are left out.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, array{type: string, status: string, workingRevisionId: string|null, terms: list<string>}>
     */
    public function forIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $pointers = [];

        foreach (DB::table('contents')
            ->leftJoin('content_revisions', 'content_revisions.id', '=', 'contents.working_revision_id')
            ->whereIn('contents.id', $ids)
            ->get(['contents.id', 'contents.type', 'contents.status', 'contents.working_revision_id', 'content_revisions.terms']) as $row) {
            /** @var list<string> $terms */
            $terms = is_string($row->terms) ? (array) json_decode($row->terms, true) : [];
            $pointers[(string) $row->id] = ['type' => (string) $row->type, 'status' => (string) $row->status, 'workingRevisionId' => $row->working_revision_id, 'terms' => $terms];
        }

        return $pointers;
    }
}
