<?php

/**
 * What confirming a shown recovery key did: its label, how many places it
 * opens, and, for a replacement, the places where the old key couldn't be
 * removed yet, filled after the commit (I-359).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

final class RecoveryKeyConfirmed
{
    /** @param list<string> $oldKeyKeptAt */
    public function __construct(
        public readonly string $label,
        public readonly int $places,
        public array $oldKeyKeptAt = [],
    ) {}
}
