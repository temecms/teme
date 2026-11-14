<?php

declare(strict_types=1);

namespace App\Domain\Design\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What DiscardDesignDraft needs: who is discarding the design's drafts.
 */
final readonly class DiscardDesignDraftInput implements OperationInput
{
    public function __construct(public string $actorId) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
