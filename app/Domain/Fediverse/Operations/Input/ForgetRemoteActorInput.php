<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What ForgetRemoteActor needs: the remote account's address and who asks.
 */
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
