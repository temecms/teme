<?php

/**
 * Rebuilds every item's comment_stats row from the comments themselves,
 * once after an import has brought its comments (I-273), with the SQL
 * teme:counts:rebuild uses for them: the approved and waiting counts, the
 * conversations and the last comment. It holds the table
 * against concurrent changes while it works, rewrites it only when a row
 * differs, and returns how many items' rows did. Only an importer may.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class RebuildCommentStats extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): int
    {
        DB::statement('LOCK TABLE comment_stats IN SHARE ROW EXCLUSIVE MODE');

        $truth = <<<'SQL'
            SELECT content_id,
                count(*) FILTER (WHERE status = 'approved') AS approved_count,
                count(*) FILTER (WHERE status = 'pending') AS pending_count,
                count(*) FILTER (WHERE depth = 1 AND status IN ('approved', 'removed')) AS thread_count,
                (array_agg(id ORDER BY id DESC) FILTER (WHERE status = 'approved'))[1] AS last_comment_id
            FROM comments GROUP BY content_id
            SQL;

        $drifted = (int) DB::scalar(<<<SQL
            SELECT count(*) FROM (
                SELECT t.content_id FROM ({$truth}) t
                LEFT JOIN comment_stats s USING (content_id)
                WHERE COALESCE(s.approved_count, 0) <> t.approved_count
                   OR COALESCE(s.pending_count, 0) <> t.pending_count
                   OR COALESCE(s.thread_count, 0) <> t.thread_count
                   OR s.last_comment_id IS DISTINCT FROM t.last_comment_id
                UNION
                SELECT s.content_id FROM comment_stats s
                LEFT JOIN ({$truth}) t USING (content_id)
                WHERE t.content_id IS NULL AND (s.approved_count <> 0 OR s.pending_count <> 0 OR s.thread_count <> 0 OR s.last_comment_id IS NOT NULL)
            ) differing
            SQL);

        if ($drifted > 0) {
            DB::table('comment_stats')->delete();
            DB::statement(<<<SQL
                INSERT INTO comment_stats (content_id, approved_count, pending_count, thread_count, last_comment_id, last_comment_at)
                SELECT t.content_id, t.approved_count, t.pending_count, t.thread_count, t.last_comment_id, c.created_at
                FROM ({$truth}) t LEFT JOIN comments c ON c.id = t.last_comment_id
                SQL);

            // A thread's pages and every list read these counts (I-263); a run's import mode holds this.
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, PageTags::EVERYTHING, [PageTags::EVERYTHING]));
        }

        return $drifted;
    }
}
