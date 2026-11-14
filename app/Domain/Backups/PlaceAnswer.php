<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * What testing a place found (I-360): whether it answered at all, whether
 * Teme's key opened the backups it made there, the room left on its disk when
 * Teme can tell, and all of it in words.
 */
final readonly class PlaceAnswer
{
    public function __construct(
        public bool $answered,
        public bool $opened,
        public ?int $freeBytes,
        public string $sentence,
    ) {}
}
