<?php

/**
 * One notice a status check raises: how serious it is, what it says, and
 * whether the plugin's settings are where it is fixed.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Status;

/**
 * One notice a status check raises: how serious it is, what it says, and
 * whether the plugin's settings are where it is fixed.
 *
 * @since 0.5.0
 */
final readonly class StatusNotice
{
    /**
     * @param  'error'|'warning'|'info'  $level
     *
     * @since 0.5.0
     */
    public function __construct(
        public string $level,
        public string $message,
        public bool $opensSettings = false,
    ) {}
}
