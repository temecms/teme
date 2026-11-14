<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The conversations an item's pages show (docs/studies/comments.md §3.1, §3.11;
 * 1.8b's D2): `comment_stats.thread_count` counts the comments at depth 1 that
 * are approved or a tombstone, kept by CommentStats::apply() as the other
 * counts are, so a page knows how many pages its thread has without a COUNT;
 * and `comments_conversations` holds those comments alone, in path order, where
 * page N's first conversation is found by position. Existing items are counted
 * once, an upsert whose new row meets the table's CHECKs (B-348).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE comment_stats ADD COLUMN thread_count integer NOT NULL DEFAULT 0');

        DB::statement(<<<'SQL'
            INSERT INTO comment_stats (content_id, thread_count)
            SELECT content_id, count(*) FROM comments
            WHERE depth = 1 AND status IN ('approved', 'removed')
            GROUP BY content_id
            ON CONFLICT (content_id) DO UPDATE SET thread_count = EXCLUDED.thread_count
            SQL);

        DB::statement('ALTER TABLE comment_stats ADD CONSTRAINT comment_stats_threads_check CHECK (thread_count >= 0)');
        DB::statement("CREATE INDEX comments_conversations ON comments (content_id, path) WHERE depth = 1 AND status IN ('approved', 'removed')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS comments_conversations');
        DB::statement('ALTER TABLE comment_stats DROP COLUMN IF EXISTS thread_count');
    }
};
