<?php

/**
 * What `RefuseImportFile` needs: the file, the sentence it is refused with,
 * and who read it (null for the operator).
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RefuseImportFileInput implements OperationInput
{
    public function __construct(
        public string $fileId,
        public string $sentence,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
