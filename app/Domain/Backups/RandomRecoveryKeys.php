<?php

/**
 * The recovery keys Teme makes in use: random, with two random groups asked
 * back (I-2401).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

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
