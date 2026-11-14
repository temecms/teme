<?php

declare(strict_types=1);

namespace App\Domain\Comments;

use App\Domain\Comments\Models\Comment;
use Illuminate\Support\Facades\DB;

/**
 * Each item's comment counts, the conversations its pages show (1.8b's D2)
 * and its last comment (I-273), kept by every comment Operation inside its
 * own transaction, so they can't drift from the rows; teme:counts:rebuild
 * is the safety net. The one place a comment's change of status reaches
 * them (D5).
 */
final class CommentStats
{
    /**
     * Moves one comment's count on its item's row from its status before a
     * change to its status after, either side null for a comment written or
     * deleted, and the conversations its pages show (1.8b's D2), and keeps
     * the item's newest approved comment as its last. Call it inside an
     * Operation's perform(), after the comment's row is written.
     */
    public static function apply(Comment $comment, ?string $before, ?string $after): void
    {
        if ($before === $after) {
            return;
        }

        $approved = ($after === CommentStatus::APPROVED ? 1 : 0) - ($before === CommentStatus::APPROVED ? 1 : 0);
        $pending = ($after === CommentStatus::PENDING ? 1 : 0) - ($before === CommentStatus::PENDING ? 1 : 0);

        // A conversation's first comment moving into or out of the thread (D2):
        // a comment at depth 1, approved or a tombstone in its place.
        $threads = $comment->depth === 1
            ? (in_array($after, CommentStatus::THREAD, true) ? 1 : 0) - (in_array($before, CommentStatus::THREAD, true) ? 1 : 0)
            : 0;

        // A new row starts at the change, never below nought: PostgreSQL holds
        // the proposed row to the table's CHECK before it finds the conflict,
        // so the deltas themselves go to the update.
        DB::statement(
            'INSERT INTO comment_stats (content_id, approved_count, pending_count, thread_count) VALUES (?, GREATEST(?, 0), GREATEST(?, 0), GREATEST(?, 0))
             ON CONFLICT (content_id) DO UPDATE SET
                 approved_count = comment_stats.approved_count + ?,
                 pending_count = comment_stats.pending_count + ?,
                 thread_count = comment_stats.thread_count + ?',
            [$comment->content_id, $approved, $pending, $threads, $approved, $pending, $threads],
        );

        if ($after === CommentStatus::APPROVED) {
            // The newest by ID, whose first part is when it was written (D16):
            // an older comment approved later never takes a newer one's place.
            DB::update(
                'UPDATE comment_stats SET last_comment_id = c.id, last_comment_at = c.created_at
                 FROM comments c
                 WHERE c.id = ? AND comment_stats.content_id = c.content_id
                   AND (comment_stats.last_comment_id IS NULL OR comment_stats.last_comment_id < c.id)',
                [$comment->id],
            );
        } elseif ($before === CommentStatus::APPROVED) {
            // The last one left approved: the newest still approved, through
            // comments_item_status, or none.
            DB::update(
                "UPDATE comment_stats SET last_comment_id = latest.id, last_comment_at = latest.created_at
                 FROM (SELECT ?::char(26) AS content_id) item
                 LEFT JOIN LATERAL (
                     SELECT id, created_at FROM comments
                     WHERE content_id = item.content_id AND status = 'approved'
                     ORDER BY id DESC LIMIT 1
                 ) latest ON true
                 WHERE comment_stats.content_id = item.content_id AND comment_stats.last_comment_id = ?",
                [$comment->content_id, $comment->id],
            );
        }
    }
}
