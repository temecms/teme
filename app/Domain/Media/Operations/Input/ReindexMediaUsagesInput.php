<?php

declare(strict_types=1);

namespace App\Domain\Media\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * A chunk of content whose media usage and links are to be re-derived.
 * Always the operator's, so there is no actor.
 */
final readonly class ReindexMediaUsagesInput implements OperationInput
{
    /**
     * @param  list<string>  $contentIds
     */
    public function __construct(public array $contentIds) {}

    public function actorId(): ?string
    {
        return null;
    }
}
