<?php

/**
 * Where recovery keys and the positions asked back come from: random in use,
 * fixed in a test.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

interface RecoveryKeySource
{
    public function make(): RecoveryKey;

    /**
     * Two different positions from 1 to 8, ascending.
     *
     * @return array{int, int}
     */
    public function positionsToAsk(): array;
}
