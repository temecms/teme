<?php

declare(strict_types=1);

namespace App\Domain\Design\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What PublishDesign needs: who is publishing the design's drafts.
 */
final readonly class PublishDesignInput implements OperationInput
{
    public function __construct(public string $actorId) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
