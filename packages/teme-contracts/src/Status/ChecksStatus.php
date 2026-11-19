<?php

/**
 * A plugin that raises notices implements this beside Plugin: a new ability is
 * a new interface.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Status;

/**
 * A plugin that raises notices implements this beside Plugin: a new
 * ability is a new interface.
 *
 * @since 0.5.0
 */
interface ChecksStatus
{
    /**
     * The checks Teme asks on every admin page.
     *
     * @return list<StatusCheck>
     *
     * @since 0.5.0
     */
    public function statusChecks(): array;
}
