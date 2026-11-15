<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What EnableAuthorActor and DisableAuthorActor need: the person whose
 * account it is, and who is asking.
 */
final readonly class AuthorActorInput implements OperationInput
{
    public function __construct(
        public string $personId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
