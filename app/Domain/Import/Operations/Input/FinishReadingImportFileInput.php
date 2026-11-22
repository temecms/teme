<?php

/**
 * What `FinishReadingImportFile` needs: the file whose every item has been
 * read, and who read it (null for the operator).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class FinishReadingImportFileInput implements OperationInput
{
    public function __construct(
        public string $fileId,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
