<?php

/**
 * Who follows the site's accounts, for a plugin granted fediverse.read: a
 * plugin's constructor asks for it by this interface.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Fediverse;

/**
 * Who follows the site's accounts, for a plugin granted fediverse.read: a
 * plugin's constructor asks for it by this interface.
 *
 * @since 1.0.0
 */
interface FediverseFollowers
{
    /**
     * The addresses of the site's accounts that can be followed now.
     *
     * @return list<string>
     *
     * @since 1.0.0
     */
    public function actors(): array;

    /**
     * How many accounts follow the site's account at $actorUri.
     *
     * @since 1.0.0
     */
    public function count(string $actorUri): int;

    /**
     * The addresses of the accounts that follow the site's account at
     * $actorUri, oldest follow first, at most $limit from $offset.
     *
     * @return list<string>
     *
     * @since 1.0.0
     */
    public function followers(string $actorUri, int $limit = 100, int $offset = 0): array;
}
