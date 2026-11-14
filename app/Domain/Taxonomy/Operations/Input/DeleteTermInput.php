<?php

declare(strict_types=1);

namespace App\Domain\Taxonomy\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What DeleteTerm needs: which term, and who is asking.
 */
final readonly class DeleteTermInput implements OperationInput
{
    public function __construct(
        public string $termId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
