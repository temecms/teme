<?php

/**
 * Names the people who write on the site, for a plugin granted people.read:
 * each one's account ID and display name, never an email address or anything
 * else about them.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\People;

use InvalidArgumentException;

/**
 * Names the people who write on the site, for a plugin granted people.read:
 * each one's account ID and display name, never an email address or
 * anything else about them.
 *
 * @since 1.0.0
 */
interface PeopleReader
{
    /**
     * The active people who may write content, one page at a time.
     *
     * @param  string|null  $after  the cursor PersonPage gave; null for the first page
     * @param  int  $limit  1 to 200
     *
     * @throws InvalidArgumentException for a cursor this reader didn't give, or a limit outside 1 to 200
     *
     * @since 1.0.0
     */
    public function writers(?string $after = null, int $limit = 100): PersonPage;
}
