<?php

declare(strict_types=1);

namespace App\Domain\Media\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What an Operation acting on one whole media item needs: which item, and
 * who is asking. A null actor is the operator.
 */
final readonly class MediaActionInput implements OperationInput
{
    public function __construct(
        public string $mediaId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
