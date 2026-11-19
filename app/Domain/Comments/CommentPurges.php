<?php

/**
 * What a comment arriving in its item's thread, or leaving it, tells the page
 * cache (docs/studies/comments.md §3.6, I-276; 1.8b's D5). An arrival sets the
 * thread's pages aside, served while one request draws each afresh; a
 * departure removes the pages that show the comment, never served stale, and
 * sets the thread's others aside, since the conversations after it move up.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments;

use App\Domain\Cache\PageTags;
use Teme\Contracts\Cache\CacheKeysFor;

final class CommentPurges
{
    /**
     * A comment now shown in its item's thread: approved, restored to it, or a
     * tombstone where nothing showed.
     */
    public static function arrived(string $itemId): CacheKeysFor
    {
        return new CacheKeysFor(CacheKeysFor::CONTENT, $itemId, [PageTags::comments($itemId)]);
    }

    /**
     * Comments no longer shown in their item's thread: taken down, or
     * tombstones deleted.
     */
    public static function left(string $itemId, string ...$commentIds): CacheKeysFor
    {
        return new CacheKeysFor(CacheKeysFor::CONTENT, $itemId, [PageTags::comments($itemId), ...array_map(PageTags::comment(...), $commentIds)]);
    }

    /**
     * A comment shown in its item's thread whose name, website or words were
     * edited (1.8c′'s D9): the pages that show it removed, never served
     * stale, since a correction can be a takedown, and the thread's others
     * set aside, as when it leaves.
     */
    public static function changed(string $itemId, string $commentId): CacheKeysFor
    {
        return self::left($itemId, $commentId);
    }
}
