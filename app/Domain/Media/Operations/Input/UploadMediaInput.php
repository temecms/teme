<?php

/**
 * What UploadMedia needs: the file on local disk, the name it was uploaded
 * with, who is asking, whether a duplicate is wanted anyway, and, for a
 * downloaded file, where it came from (I-453); and the content item it was
 * uploaded for, if any.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class UploadMediaInput implements OperationInput
{
    public function __construct(
        public string $path,
        public string $originalFilename,
        public ?string $actorId,
        public bool $allowDuplicate = false,
        public ?string $sourceUrl = null,
        public ?string $uploadedFor = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
