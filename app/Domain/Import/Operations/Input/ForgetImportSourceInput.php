<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `ForgetImportSource` needs: the source whose application password to
 * erase, and who is asking (null for the operator).
 */
final readonly class ForgetImportSourceInput implements OperationInput
{
    public function __construct(
        public string $sourceId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
