<?php

declare(strict_types=1);

namespace App\Domain\Cache\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What ClearPageCache needs: who is asking.
 */
final readonly class ClearPageCacheInput implements OperationInput
{
    public function __construct(public ?string $actorId = null) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
