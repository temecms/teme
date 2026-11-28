<?php

/**
 * What the running Operations tell the page cache (I-263): a list of tags
 * for each Operation executing, nested as Operations nest, which
 * BaseOperation opens before perform(), closes after it, and purges after
 * the outermost commit (rule 6). Scoped to a request or a job.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cache;

use LogicException;
use Teme\Contracts\Cache\CacheKeysFor;
use Throwable;

final class PublicChanges
{
    /** @var array<int, array<string, true>> */
    private array $open = [];

    /**
     * Opens a list for an Operation about to perform.
     */
    public function open(): void
    {
        $this->open[] = [];
    }

    /**
     * Whether an Operation is performing now, so a change may be noted to
     * the list of its tags rather than purged by a call of its own.
     */
    public function inOperation(): bool
    {
        return $this->open !== [];
    }

    /**
     * Adds these changes' tags to the list of the innermost Operation
     * executing.
     *
     * @throws LogicException outside an Operation: only an Operation changes what the public sees (rule 1)
     */
    public function add(CacheKeysFor ...$changes): void
    {
        $innermost = array_key_last($this->open);

        if ($innermost === null) {
            throw new LogicException('Only an Operation changes what the public sees (rule 1): tell the page cache from inside perform().');
        }

        foreach ($changes as $change) {
            // Later (I-263): from phase 5 the collect hook's listeners add their keys here (study C).
            foreach ($change->keys as $key) {
                $this->open[$innermost][$key] = true;
            }
        }
    }

    /**
     * Closes the innermost Operation's list, and returns its tags, each once, sorted.
     *
     * @return list<string>
     */
    public function close(): array
    {
        $tags = array_map(strval(...), array_keys(array_pop($this->open) ?? []));
        sort($tags);

        return $tags;
    }

    /**
     * Tells the page cache of these changes, now, while it is on: the pages
     * that show what changed are removed, and those that only list by a
     * query that changed are set aside (PageCache::invalidate()). It runs
     * after the change has committed, so a failure is reported, never
     * thrown: nothing may undo a saved change or turn it into an error.
     *
     * @param  list<string>  $tags
     */
    public function purge(array $tags): void
    {
        if ($tags === [] || ! PageCache::enabled()) {
            return;
        }

        try {
            app(PageCache::class)->invalidate($tags);
        } catch (Throwable $failure) {
            report($failure);
        }
    }
}
