<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * Whose autosave, in which article, to throw away.
 */
final readonly class DiscardAutosaveInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
