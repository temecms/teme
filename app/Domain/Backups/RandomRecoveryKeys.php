<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * The recovery keys Teme makes in use: random, with two random groups asked
 * back (I-2401).
 */
final readonly class RandomRecoveryKeys implements RecoveryKeySource
{
    public function make(): RecoveryKey
    {
        return RecoveryKey::generate();
    }

    public function positionsToAsk(): array
    {
        do {
            $first = random_int(1, RecoveryKey::GROUPS);
            $second = random_int(1, RecoveryKey::GROUPS);
        } while ($first === $second);

        return [min($first, $second), max($first, $second)];
    }
}
