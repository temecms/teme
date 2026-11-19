<?php

/**
 * What ClearPageCache needs: who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cache\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ClearPageCacheInput implements OperationInput
{
    public function __construct(public ?string $actorId = null) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
