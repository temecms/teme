<?php

/**
 * What `DeleteImportFile` needs: the export file to remove, and who is asking
 * (null for the operator).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class DeleteImportFileInput implements OperationInput
{
    public function __construct(
        public string $fileId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
