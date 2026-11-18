<?php

/**
 * Which pages a change draws again, on the queue (docs/studies/page-cache.md
 * §3.11): up to `page_cache.warm.limit` of the pages it is about to remove,
 * then of those it is about to set aside, read from the store before they
 * go; never a search's page, a list's later page, or a page that answered
 * anything but 200; the home page first (D9). None while warming is off, or
 * on the sync queue, where the request that made the change would draw them
 * itself (D10).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cache;

use App\Domain\Api\ApiPaths;
use Throwable;

final readonly class PageWarming
{
    public function __construct(private PageStore $store) {}

    /**
     * Whether a change's pages are drawn again: warming is on, and a queue
     * other than sync runs the jobs.
     */
    public static function enabled(): bool
    {
        $connection = (string) config('queue.default');

        return (bool) config('page_cache.warm.enabled') && config("queue.connections.{$connection}.driver") !== 'sync';
    }

    /**
     * The addresses to draw again after a change to these tags, in the order
     * they are drawn. A store that can't be read warms nothing, reported: the
     * change goes on.
     *
     * @param  list<string>  $things
     * @param  list<string>  $lists
     *
     * @return list<string>
     */
    public function addresses(array $things, array $lists): array
    {
        $limit = max(0, (int) config('page_cache.warm.limit'));

        if ($limit === 0 || ! self::enabled()) {
            return [];
        }

        try {
            // The pages that show a thing, then those that list by a query.
            $keys = [...$this->store->keys($things, $limit), ...$this->store->keys($lists, $limit)];
        } catch (Throwable $failure) {
            report($failure);

            return [];
        }

        $wanted = array_values(array_unique(array_filter($keys, self::worthDrawing(...))));
        // The home page first: the most read, and in nearly every change's lists.
        usort($wanted, fn (string $one, string $other): int => self::isHome($other) <=> self::isHome($one));

        return array_slice($wanted, 0, $limit);
    }

    /**
     * Whether a page is worth drawing before anyone asks for it: never a
     * search's, whose key the reader writes, nor a list's later page, which
     * few read, nor an API answer, llms.txt or a .md page, which their next
     * reader draws.
     */
    private static function worthDrawing(string $key): bool
    {
        $path = (string) parse_url($key, PHP_URL_PATH);

        return ! str_contains($key, '?')
            && preg_match('#/page/\d+/?$#', $path) !== 1
            && ! ApiPaths::is($path)
            && ! str_ends_with($path, '.md')
            && $path !== '/llms.txt';
    }

    private static function isHome(string $key): bool
    {
        return parse_url($key, PHP_URL_PATH) === '/';
    }
}
