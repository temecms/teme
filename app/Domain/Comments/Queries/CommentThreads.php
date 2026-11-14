<?php

declare(strict_types=1);

namespace App\Domain\Comments\Queries;

use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\Models\Comment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The comment thread's reads for the public site (docs/studies/comments.md
 * §3.1, I-272): a page of an item's conversations, the page a comment is on,
 * one comment shown with everything above it, the item's counts, and a
 * browser's own comments. Each
 * statement names the shown statuses and `depth = 1` word for word, as the
 * indexes' predicates do, so any plan can use them (CommentStatus::THREAD).
 */
final readonly class CommentThreads
{
    /** The columns a thread is drawn from: never an email address, an IP address, a browser, a token's hash or a moderation record (D19). */
    private const array COLUMNS = ['id', 'content_id', 'parent_id', 'path', 'depth', 'kind', 'status', 'author_name', 'author_url', 'author_user_id', 'body', 'legacy_id', 'created_at'];

    /** The most of one browser's own comments a page is given at once (D6). */
    public const int OWN_LIMIT = 20;

    /**
     * The comments page $number shows of this item's thread, in display
     * order: up to $perPage conversations, oldest first, each with every reply
     * under it (I-272), less a reply whose comment above it isn't shown (D3).
     * Two statements, whichever page: where the page starts and the next
     * begins, found by position among the conversations' first comments
     * alone (comments_conversations, D2); then the page, one range of
     * comments_thread.
     *
     * @return list<Comment>
     */
    public function page(string $itemId, int $number, int $perPage): array
    {
        $starts = DB::select(
            "SELECT path FROM comments
             WHERE content_id = ? AND depth = 1 AND status IN ('approved', 'removed')
             ORDER BY path OFFSET ? LIMIT ?",
            [$itemId, ($number - 1) * $perPage, $perPage + 1],
        );

        if ($starts === []) {
            return [];
        }

        $from = (string) $starts[0]->path;
        $until = isset($starts[$perPage]) ? (string) $starts[$perPage]->path : null;

        $query = Comment::query()
            ->select(self::COLUMNS)
            ->where('content_id', $itemId)
            ->whereRaw("status IN ('approved', 'removed')")
            ->where('path', '>=', $from);

        if ($until !== null) {
            $query->where('path', '<', $until);
        }

        return self::underShown($query->orderBy('path')->get()->all());
    }

    /**
     * The page a shown comment is on: its conversation's place among the
     * item's, one count over comments_conversations (I-272, Drupal's
     * getDisplayOrdinal).
     */
    public function pageOf(Comment $comment, int $perPage): int
    {
        $before = (int) DB::scalar(
            "SELECT count(*) FROM comments
             WHERE content_id = ? AND depth = 1 AND status IN ('approved', 'removed') AND path < ?",
            [$comment->content_id, substr($comment->path, 0, 27)],
        );

        return intdiv($before, $perPage) + 1;
    }

    /**
     * The comment, when it and every comment above it are shown (D3), or null.
     */
    public function shown(string $id): ?Comment
    {
        $comment = Comment::query()->select(self::COLUMNS)->whereRaw("status IN ('approved', 'removed')")->find($id);

        if ($comment === null) {
            return null;
        }

        $above = array_slice(explode('/', rtrim($comment->path, '/')), 0, -1);

        return $above === [] || Comment::query()->whereIn('id', $above)->whereRaw("status IN ('approved', 'removed')")->count() === count($above)
            ? $comment
            : null;
    }

    /**
     * The item's approved comments, pings included (D4), and the
     * conversations its pages show (D2): one read of its comment_stats row,
     * nought each when it has none.
     *
     * @return array{approved: int, threads: int}
     */
    public function stats(string $itemId): array
    {
        $row = DB::table('comment_stats')->where('content_id', $itemId)->first(['approved_count', 'thread_count']);

        return $row === null
            ? ['approved' => 0, 'threads' => 0]
            : ['approved' => (int) $row->approved_count, 'threads' => (int) $row->thread_count];
    }

    /**
     * One browser's own comments on an item, found by its key's hash
     * (comments_token): those that wait or were judged spam, and those
     * approved since $approvedSince; the newest OWN_LIMIT, in path order, so
     * a comment comes before its replies. The hash is matched, never selected.
     *
     * @return list<Comment>
     */
    public function own(string $itemId, string $tokenHash, CarbonInterface $approvedSince): array
    {
        $rows = Comment::query()
            ->select(self::COLUMNS)
            ->where('commenter_token_hash', $tokenHash)
            ->where('content_id', $itemId)
            ->where(fn (Builder $query) => $query
                ->whereIn('status', [CommentStatus::PENDING, CommentStatus::SPAM])
                ->orWhere(fn (Builder $approved) => $approved->where('status', CommentStatus::APPROVED)->where('updated_at', '>=', $approvedSince)))
            ->orderByDesc('id')
            ->limit(self::OWN_LIMIT)
            ->get()
            ->all();

        usort($rows, fn (Comment $a, Comment $b): int => strcmp($a->path, $b->path));

        return $rows;
    }

    /**
     * The shown comments these rows are stored under, by ID: one statement,
     * or none when no row is a reply.
     *
     * @param  list<Comment>  $rows
     *
     * @return array<string, Comment>
     */
    public function parentsOf(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (Comment $row): ?string => $row->parent_id, $rows))));

        if ($ids === []) {
            return [];
        }

        $parents = [];

        foreach (Comment::query()->select(self::COLUMNS)->whereIn('id', $ids)->whereRaw("status IN ('approved', 'removed')")->get() as $parent) {
            $parents[$parent->id] = $parent;
        }

        return $parents;
    }

    /**
     * The comment as its writer sees it, whatever its status but removed, or null.
     */
    public function written(string $id): ?Comment
    {
        return Comment::query()->select(self::COLUMNS)->whereKey($id)->where('status', '!=', CommentStatus::REMOVED)->first();
    }

    /**
     * Rows in path order, less each whose parent isn't among those kept: a
     * parent always comes before its replies, so one pass does it (D3).
     *
     * @param  array<int, Comment>  $rows
     *
     * @return list<Comment>
     */
    private static function underShown(array $rows): array
    {
        $kept = [];
        $shown = [];

        foreach ($rows as $row) {
            if ($row->depth === 1 || isset($kept[(string) $row->parent_id])) {
                $kept[$row->id] = true;
                $shown[] = $row;
            }
        }

        return $shown;
    }
}
