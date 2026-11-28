<?php

/**
 * What `AttachUploadsCopy` needs: the old site (its ID or its address), the
 * folder or zip of its uploads already on this server, and who is attaching
 * it (null for the operator).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class AttachUploadsCopyInput implements OperationInput
{
    public function __construct(
        public string $source,
        public string $path,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
