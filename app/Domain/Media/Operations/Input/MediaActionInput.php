<?php

/**
 * What an Operation acting on one whole media item needs: which item, and
 * who is asking. A null actor is the operator.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
