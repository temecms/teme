<?php

/**
 * Marks the span in which a plugin's content writer makes a publish or a
 * schedule, so the before-save check is not asked for a plugin's own write
 * (a listener that publishes would otherwise call back into its own check).
 * Bound scoped, like the plugin job's actor: one request or job.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents;

use Closure;

final class PluginWrites
{
    private int $depth = 0;

    /**
     * Runs a plugin's write with the span marked, and unmarks it after, even
     * when the write fails.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     *
     * @return T
     */
    public function within(Closure $write): mixed
    {
        $this->depth++;

        try {
            return $write();
        } finally {
            $this->depth--;
        }
    }

    /**
     * Returns whether a plugin's write is running now.
     */
    public function active(): bool
    {
        return $this->depth > 0;
    }
}
