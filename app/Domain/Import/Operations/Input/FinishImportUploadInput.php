<?php

/**
 * What `FinishImportUpload` needs: the upload to close, and who is asking
 * (null for the operator).
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class FinishImportUploadInput implements OperationInput
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
