<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * One batch of the search rebuild a change of site language starts, and
 * who asked for it (I-392): the administrator who changed the language.
 */
final readonly class ReindexSearchInput implements OperationInput
{
    public function __construct(public ?string $actorId) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
