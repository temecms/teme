<?php

/**
 * Where the page cache keeps pages: a driver (spec §5.6). Nothing a store
 * keeps may grow with the number of pages in one piece, and nothing lists a
 * folder to find a page (docs/studies/page-cache.md §3.8).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cache;

use Illuminate\Contracts\Cache\Lock;

interface PageStore
{
    /**
     * The page stored under this key, expired or not, or null when there is
     * none or it can't be read.
     */
    public function get(PageKey $key): ?PageEntry;

    /**
     * The copy of the page set aside under this key, or null: a page one of
     * whose lists changed, served, marked STALE, only while another request
     * draws it afresh (docs/studies/page-cache.md §3.9).
     */
    public function stale(PageKey $key): ?PageEntry;

    /**
     * Stores the page under its key, replacing any page there, and records
     * its tags, of which it needs at least one: they are how it is found again.
     */
    public function put(PageEntry $entry): void;

    /**
     * Removes every stored page that carries any of these tags, `*` meaning
     * every page, and returns how many: found through the tags (I-263).
     *
     * @param  list<string>  $tags
     */
    public function purge(array $tags): int;

    /**
     * Sets aside each stored page that carries any of these tags as a stale
     * copy, kept until $until, and returns how many were set aside. The page
     * stored again replaces its copy, and a purge of any of its tags removes
     * it.
     *
     * @param  list<string>  $tags
     */
    public function markStale(array $tags, int $until): int;

    /**
     * Removes the page stored under this key, if there is one: the race
     * guard's undo of a page it wrote as a purge landed (I-264).
     */
    public function forget(string $key): void;

    /**
     * Removes every stored page, and returns how many.
     */
    public function flush(): int;

    /**
     * Removes the pages that expired at or before $now, and returns how many.
     */
    public function prune(int $now): int;

    /**
     * The keys of the stored pages, or copies set aside, that carry any of
     * these tags and answered 200: at most $limit pages are read, so fewer
     * may come back. The addresses warming draws again after a change, read
     * before the change takes them (docs/studies/page-cache.md §3.11);
     * nothing lists a folder or a keyspace.
     *
     * @param  list<string>  $tags
     *
     * @return list<string>
     */
    public function keys(array $tags, int $limit): array;

    /**
     * The lock one request holds while it draws the page at this key, so
     * the others are given its stale copy or wait for it
     * (docs/studies/page-cache.md §3.9), kept where the store keeps its
     * pages: every server that shares the pages shares their locks.
     */
    public function lock(PageKey $key, int $seconds): Lock;
}
