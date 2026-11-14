<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * A request for review: which revision of which article is handed over, and
 * who hands it over.
 */
final readonly class SubmitForReviewInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public string $revisionId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
