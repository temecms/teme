<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * A chunk of content whose derived document values are to be counted, or
 * filled in when $fix is true. Always the operator's, so there is no actor.
 */
final readonly class RebuildDocumentDerivationsInput implements OperationInput
{
    /**
     * @param  list<string>  $contentIds
     */
    public function __construct(
        public array $contentIds,
        public bool $fix,
    ) {}

    public function actorId(): ?string
    {
        return null;
    }
}
