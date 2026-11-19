<?php

/**
 * Fills in, for a chunk of content, the values 1.5b derives from a document
 * but never wrote for revisions made before it (I-422): a revision's plain
 * text and word count, and a published item's search text. It writes only
 * those derived columns, never a revision's document, title or fields, and
 * running it again changes nothing. The operator only, one chunk per
 * transaction.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Cache\Footprints;
use App\Domain\Cards\PlainText;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\RebuildDocumentDerivationsInput;
use App\Domain\Contents\SearchText;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class RebuildDocumentDerivations extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        if (! Operator::isPresent()) {
            throw new AuthorizationException('Only the operator fills in derived document values.');
        }
    }

    protected function validate(OperationInput $input): void {}

    /**
     * How many revisions had no plain text, and how many published items
     * had stale search columns, in this chunk: filled in, or found when
     * only checking.
     *
     * @return array{revisions: int, searchable: int}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var RebuildDocumentDerivationsInput $input */
        $contents = Content::withTrashed()->whereIn('id', $input->contentIds)->orderBy('id')->lockForUpdate()->get();
        $ids = $contents->pluck('id')->all();

        $revisions = DB::table('content_revisions')->whereIn('content_id', $ids)->whereNull('plain_text')
            ->orderBy('id')->get(['id', 'document']);

        if ($input->fix) {
            foreach ($revisions as $revision) {
                $document = (array) json_decode((string) $revision->document, true);

                DB::table('content_revisions')->where('id', $revision->id)->update([
                    'plain_text' => PlainText::of($document),
                    'word_count' => PlainText::words($document),
                ]);
            }
        }

        // Counted after any fill, so the comparison uses the revisions'
        // plain text as it now stands.
        $searchable = $this->staleSearch($ids)->count();

        if ($input->fix) {
            foreach ($contents as $content) {
                if (! $content->trashed() && $content->status === 'published' && $content->published_revision_id !== null) {
                    SearchText::sync($content);
                }
            }
        }

        // A filled-in word count is an article's reading time, and search
        // text what a search finds (I-263).
        if ($input->fix && ($revisions->isNotEmpty() || $searchable > 0)) {
            foreach (Footprints::contents($contents) as $id => $keys) {
                $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $id, $keys));
            }
        }

        return ['revisions' => $revisions->count(), 'searchable' => $searchable];
    }

    /**
     * The published, live items in the chunk whose search columns do not
     * match their published revision.
     *
     * @param  list<string>  $ids
     */
    private function staleSearch(array $ids): Builder
    {
        return DB::table('contents as c')
            ->join('content_revisions as r', 'r.id', '=', 'c.published_revision_id')
            ->whereIn('c.id', $ids)
            ->whereNull('c.deleted_at')
            ->where('c.status', 'published')
            ->where(fn (Builder $query) => $query->whereNull('c.search_tsv')
                ->orWhereRaw('c.search_text IS DISTINCT FROM r.plain_text'));
    }
}
