<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Concerns;

use App\Domain\Cache\PageTags;
use App\Domain\Contents\ContentCounts;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Settings\SettingsReader;

/**
 * The counts every list and thread reads, compared with the rows and
 * rewritten where they differ (I-141): the operator's weekly safety net, and
 * an import's at its end. The month rows are counted too.
 */
trait RecountsContent
{
    /**
     * @return array{drifted: int, fixed: bool}
     */
    private function recount(bool $fix): array
    {
        DB::statement('LOCK TABLE content_counts, media_counts, term_counts, comment_stats IN SHARE ROW EXCLUSIVE MODE');

        $months = ContentCounts::monthTruth(app(SettingsReader::class)->string('general', 'timezone'));

        $truth = <<<SQL
            SELECT type, CASE WHEN deleted_at IS NOT NULL THEN 'trash' WHEN status = 'published' AND visibility = 'private' THEN 'private' ELSE status END AS bucket, 0 AS author_id, count(*) AS count
            FROM contents GROUP BY 1, 2
            UNION ALL
            SELECT type, CASE WHEN deleted_at IS NOT NULL THEN 'trash' WHEN status = 'published' AND visibility = 'private' THEN 'private' ELSE status END, author_id, count(*)
            FROM contents WHERE author_id IS NOT NULL GROUP BY 1, 2, 3
            UNION ALL
            SELECT type, bucket, author_id, count FROM ({$months}) months
            SQL;

        $drifted = (int) DB::scalar(<<<SQL
            SELECT count(*) FROM (
                SELECT t.type, t.bucket, t.author_id FROM ({$truth}) t
                LEFT JOIN content_counts c USING (type, bucket, author_id)
                WHERE c.count IS DISTINCT FROM t.count
                UNION
                SELECT c.type, c.bucket, c.author_id FROM content_counts c
                LEFT JOIN ({$truth}) t USING (type, bucket, author_id)
                WHERE t.count IS NULL AND c.count <> 0
            ) differing
            SQL);

        $termTruth = 'SELECT term_id, count(*) AS n FROM content_terms WHERE scope = \'published\' GROUP BY term_id';

        $termDrift = (int) DB::scalar(<<<SQL
            SELECT count(*) FROM terms t
            LEFT JOIN ({$termTruth}) x ON x.term_id = t.id
            WHERE t.published_count <> COALESCE(x.n, 0)
            SQL);

        $workingTruth = 'SELECT term_id, count(*) AS n FROM content_terms WHERE scope = \'working\' GROUP BY term_id';

        $workingDrift = (int) DB::scalar(<<<SQL
            SELECT count(*) FROM terms t
            LEFT JOIN ({$workingTruth}) x ON x.term_id = t.id
            WHERE t.working_count <> COALESCE(x.n, 0)
            SQL);

        $perTypeTruth = <<<'SQL'
            SELECT ct.term_id, c.type,
                count(*) FILTER (WHERE ct.scope = 'published') AS published,
                count(*) FILTER (WHERE ct.scope = 'working') AS working
            FROM content_terms ct JOIN contents c ON c.id = ct.content_id
            GROUP BY ct.term_id, c.type
            SQL;

        $perTypeDrift = (int) DB::scalar(<<<SQL
            SELECT count(*) FROM (
                SELECT t.term_id, t.type FROM ({$perTypeTruth}) t
                LEFT JOIN term_counts c USING (term_id, type)
                WHERE c.published IS DISTINCT FROM t.published OR c.working IS DISTINCT FROM t.working
                UNION
                SELECT c.term_id, c.type FROM term_counts c
                LEFT JOIN ({$perTypeTruth}) t USING (term_id, type)
                WHERE t.term_id IS NULL AND (c.published <> 0 OR c.working <> 0)
            ) differing
            SQL);

        $mediaTruth = <<<'SQL'
            SELECT kind, CASE WHEN deleted_at IS NOT NULL THEN 'trash' ELSE 'live' END AS bucket, count(*) AS count
            FROM media GROUP BY 1, 2
            SQL;

        $mediaDrift = (int) DB::scalar(<<<SQL
            SELECT count(*) FROM (
                SELECT t.kind, t.bucket FROM ({$mediaTruth}) t
                LEFT JOIN media_counts c USING (kind, bucket)
                WHERE c.count IS DISTINCT FROM t.count
                UNION
                SELECT c.kind, c.bucket FROM media_counts c
                LEFT JOIN ({$mediaTruth}) t USING (kind, bucket)
                WHERE t.count IS NULL AND c.count <> 0
            ) differing
            SQL);

        $usesTruth = "SELECT media_id, count(*) FILTER (WHERE scope = 'working') AS w, count(*) FILTER (WHERE scope = 'published') AS p FROM media_usages GROUP BY media_id";

        $usesDrift = (int) DB::scalar(<<<SQL
            SELECT count(*) FROM media m
            LEFT JOIN ({$usesTruth}) x ON x.media_id = m.id
            WHERE m.working_uses <> COALESCE(x.w, 0) OR m.published_uses <> COALESCE(x.p, 0)
            SQL);

        // Each item's comment counts and its newest approved comment (I-273),
        // from the rows. An item with no row counts as all nought.
        $commentTruth = <<<'SQL'
            SELECT content_id,
                count(*) FILTER (WHERE status = 'approved') AS approved_count,
                count(*) FILTER (WHERE status = 'pending') AS pending_count,
                count(*) FILTER (WHERE depth = 1 AND status IN ('approved', 'removed')) AS thread_count,
                (array_agg(id ORDER BY id DESC) FILTER (WHERE status = 'approved'))[1] AS last_comment_id
            FROM comments GROUP BY content_id
            SQL;

        $commentDrift = (int) DB::scalar(<<<SQL
            SELECT count(*) FROM (
                SELECT t.content_id FROM ({$commentTruth}) t
                LEFT JOIN comment_stats s USING (content_id)
                WHERE COALESCE(s.approved_count, 0) <> t.approved_count
                   OR COALESCE(s.pending_count, 0) <> t.pending_count
                   OR COALESCE(s.thread_count, 0) <> t.thread_count
                   OR s.last_comment_id IS DISTINCT FROM t.last_comment_id
                UNION
                SELECT s.content_id FROM comment_stats s
                LEFT JOIN ({$commentTruth}) t USING (content_id)
                WHERE t.content_id IS NULL AND (s.approved_count <> 0 OR s.pending_count <> 0 OR s.thread_count <> 0 OR s.last_comment_id IS NOT NULL)
            ) differing
            SQL);

        // What the lists page by: the status counts and each term's published counts.
        $listsDrifted = $drifted + $termDrift + $perTypeDrift;

        $drifted += $termDrift + $workingDrift + $perTypeDrift + $mediaDrift + $usesDrift + $commentDrift;

        if ($fix && $drifted > 0) {
            DB::table('content_counts')->delete();
            DB::statement("INSERT INTO content_counts (type, bucket, author_id, count) {$truth}");

            DB::statement(<<<SQL
                UPDATE terms t SET published_count = COALESCE(x.n, 0)
                FROM terms t2 LEFT JOIN ({$termTruth}) x ON x.term_id = t2.id
                WHERE t.id = t2.id AND t.published_count <> COALESCE(x.n, 0)
                SQL);

            DB::statement(<<<SQL
                UPDATE terms t SET working_count = COALESCE(x.n, 0)
                FROM terms t2 LEFT JOIN ({$workingTruth}) x ON x.term_id = t2.id
                WHERE t.id = t2.id AND t.working_count <> COALESCE(x.n, 0)
                SQL);

            DB::table('term_counts')->delete();
            DB::statement("INSERT INTO term_counts (term_id, type, published, working) {$perTypeTruth}");

            DB::table('media_counts')->delete();
            DB::statement("INSERT INTO media_counts (kind, bucket, count) {$mediaTruth}");

            DB::statement(<<<SQL
                UPDATE media m SET working_uses = COALESCE(x.w, 0), published_uses = COALESCE(x.p, 0)
                FROM media m2 LEFT JOIN ({$usesTruth}) x ON x.media_id = m2.id
                WHERE m.id = m2.id AND (m.working_uses <> COALESCE(x.w, 0) OR m.published_uses <> COALESCE(x.p, 0))
                SQL);

            DB::table('comment_stats')->delete();
            DB::statement(<<<SQL
                INSERT INTO comment_stats (content_id, approved_count, pending_count, thread_count, last_comment_id, last_comment_at)
                SELECT t.content_id, t.approved_count, t.pending_count, t.thread_count, t.last_comment_id, c.created_at
                FROM ({$commentTruth}) t LEFT JOIN comments c ON c.id = t.last_comment_id
                SQL);
        }

        // Every list pages by these counts, and a thread's pages by its item's
        // comment counts, so a fix redraws them all (I-263).
        if ($fix && ($listsDrifted > 0 || $commentDrift > 0)) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, PageTags::EVERYTHING, [PageTags::EVERYTHING]));
        }

        return ['drifted' => $drifted, 'fixed' => $fix && $drifted > 0];
    }
}
