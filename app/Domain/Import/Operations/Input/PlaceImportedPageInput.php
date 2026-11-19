<?php

/**
 * One imported page to put back under its WordPress parent (I-3363): the
 * site, the page's WordPress ID, its rank among its WordPress siblings, and
 * who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
