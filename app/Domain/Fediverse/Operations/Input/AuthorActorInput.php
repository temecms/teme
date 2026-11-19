<?php

/**
 * What EnableAuthorActor and DisableAuthorActor need: the person whose
 * account it is, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
