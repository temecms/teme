<?php

/**
 * Tells Teme that a plugin's insertion rules changed, so pages that drew
 * insertions are purged after the Operation commits.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Insertions;

/**
 * Tells Teme that a plugin's insertion rules changed, so pages that drew
 * insertions are purged after the Operation commits. Call it inside an
 * Operation's perform(), or from a job: a job's call purges, after its work
 * commits, the pages of the content items it names, or else the whole site
 * at most once a minute.
 *
 * @since 1.0.0
 */
interface InsertionsChanged
{
    /**
     * Notes that insertion rules changed, for the page cache to purge after commit.
     *
     * @param  list<string>|null  $contentIds  the content items whose pages changed, from a job (since next); null for the whole site
     *
     * @since 1.0.0
     */
    public function notify(?array $contentIds = null): void;
}
