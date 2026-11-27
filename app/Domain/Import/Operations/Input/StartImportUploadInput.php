<?php

/**
 * What `StartImportUpload` needs: the export file’s name and size, and who
 * is asking (null for the operator).
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class StartImportUploadInput implements OperationInput
{
    public function __construct(
        public string $name,
        public int $sizeBytes,
        public ?string $actorId,
        public ?string $reader = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
