<?php

/**
 * What a plugin's importer may ask of the run it is part of: what the
 * WordPress posts and people core imported became in Teme, and whether the run
 * is a catch-up.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Import;

use Teme\Contracts\Content\ItemRef;

/**
 * What a plugin's importer may ask of the run it is part of: what the
 * WordPress posts and people core imported became in Teme, and whether
 * the run is a catch-up.
 *
 * @since 1.0.0
 */
interface ImportScope
{
    /**
     * Returns what an imported WordPress post became in Teme, or null when
     * the import didn't bring it or it is gone.
     *
     * @since 1.0.0
     */
    public function itemFor(int $sourcePostId): ?ItemRef;

    /**
     * Returns the ID of the Teme person a WordPress user became, or null
     * when the import didn't bring them.
     *
     * @since 1.0.0
     */
    public function personFor(int $sourceUserId): ?string;

    /**
     * Returns whether this run catches up on what changed since the last
     * finished import, rather than bringing the whole site.
     *
     * @since 1.0.0
     */
    public function isCatchUp(): bool;
}
