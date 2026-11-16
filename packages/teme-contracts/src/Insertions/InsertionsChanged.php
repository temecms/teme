<?php

declare(strict_types=1);

namespace Teme\Contracts\Insertions;

/**
 * Tells Teme that a plugin's insertion rules changed, so pages that drew
 * insertions are purged after the Operation commits. Call it only inside
 * an Operation's perform(); outside one it does nothing useful and may
 * refuse.
 *
 * @since 0.8.0
 */
interface InsertionsChanged
{
    /**
     * Notes that insertion rules changed, for the page cache to purge after commit.
     *
     * @since 0.8.0
     */
    public function notify(): void;
}
