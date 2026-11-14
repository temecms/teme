<?php

declare(strict_types=1);

namespace App\Domain\Media\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What AttachImportedMedia needs: the media item, the post or page it was
 * uploaded for, and who is importing it.
 */
final readonly class AttachImportedMediaInput implements OperationInput
{
    public function __construct(
        public string $mediaId,
        public string $contentId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
