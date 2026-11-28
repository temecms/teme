<?php

/**
 * A plugin area that supplies a count for each of its pages' tabs.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Admin;

/**
 * A plugin area that supplies a count for each of its pages' tabs.
 * Implemented on the same class as PluginArea. A missing or failed page
 * shows no number.
 *
 * @since next
 */
interface HasAreaCounts
{
    /**
     * Counts by page id for tabs that have one.
     *
     * @return array<string, int>
     *
     * @since next
     */
    public function counts(AreaContext $context): array;
}
