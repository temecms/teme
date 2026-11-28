<?php

/**
 * What the uploads copy's finish and cancel need: the upload, and who is
 * asking (null for the operator).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class UploadsCopyUploadInput implements OperationInput
{
    public function __construct(
        public string $uploadId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
