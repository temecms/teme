<?php

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `SetPicture` needs: whose picture, the image in Media, and who is
 * asking.
 */
final readonly class SetPictureInput implements OperationInput
{
    public function __construct(
        public string $userId,
        public string $mediaId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
