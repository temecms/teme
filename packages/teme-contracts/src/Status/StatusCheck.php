<?php

/**
 * Something about a plugin an administrator must know until it is fixed.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Status;

/**
 * Something about a plugin an administrator must know until it is fixed. Teme
 * asks on every admin page, so it answers from what it already has.
 *
 * @since 0.5.0
 */
interface StatusCheck
{
    /**
     * The notice to show, or null when there is nothing to say.
     *
     * @since 0.5.0
     */
    public function notice(): ?StatusNotice;
}
