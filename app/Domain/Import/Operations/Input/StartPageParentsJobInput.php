<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * A site imported before whose pages to put back under their WordPress
 * parents (I-3363), and who is asking: null for the operator.
 */
final readonly class StartPageParentsJobInput implements OperationInput
{
    public function __construct(
        public string $sourceId,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
