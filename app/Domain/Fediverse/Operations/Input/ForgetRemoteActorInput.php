<?php

/**
 * What ForgetRemoteActor needs: the remote account's address and who asks.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ForgetRemoteActorInput implements OperationInput
{
    public function __construct(
        public string $remoteActorUri,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
