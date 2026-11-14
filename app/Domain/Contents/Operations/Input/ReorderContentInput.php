<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What ReorderContent needs: which item, which way it moves among its
 * siblings (up or down), and who is asking.
 */
final readonly class ReorderContentInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public string $direction,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
