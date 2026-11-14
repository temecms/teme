<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * One imported page to put back under its WordPress parent (I-3363): the
 * site, the page's WordPress ID, its rank among its WordPress siblings, and
 * who is asking.
 */
final readonly class PlaceImportedPageInput implements OperationInput
{
    public function __construct(
        public string $sourceId,
        public string $objectId,
        public int $rank,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
