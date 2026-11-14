<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * What adding a place did: its ID, and the labels of the recovery keys that
 * don't open it yet (I-2402).
 */
final readonly class PlaceAdded
{
    /** @param list<string> $labelsMissing */
    public function __construct(public string $placeId, public array $labelsMissing) {}
}
