<?php

/**
 * What `StartUploadsCopy` needs: the site the copy is for, the zip's name and
 * size, and who is uploading it (null for the operator).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class StartUploadsCopyInput implements OperationInput
{
    public function __construct(
        public string $sourceId,
        public string $name,
        public int $sizeBytes,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
