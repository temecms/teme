<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * Where recovery keys and the positions asked back come from: random in use,
 * fixed in a test.
 */
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
