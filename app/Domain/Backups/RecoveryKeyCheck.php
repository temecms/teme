<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * What checking a recovery key found (I-359): its label when the site knows
 * it, the places it opens and the places it doesn't, oldest first.
 */
final readonly class RecoveryKeyCheck
{
    /**
     * @param  list<string>  $opens
     * @param  list<string>  $doesNotOpen
     */
    public function __construct(
        public ?string $label,
        public array $opens,
        public array $doesNotOpen,
    ) {}
}
