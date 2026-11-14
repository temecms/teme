<?php

declare(strict_types=1);

namespace App\Domain\Media\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * A new file for an existing item: which item, the file on local disk, the
 * name it was uploaded with, and who is asking.
 */
final readonly class ReplaceMediaFileInput implements OperationInput
{
    public function __construct(
        public string $mediaId,
        public string $path,
        public string $originalFilename,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
