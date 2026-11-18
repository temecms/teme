<?php

/**
 * What `ForgetImportSource` needs: the source whose application password to
 * erase, and who is asking (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
