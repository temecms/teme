<?php

/**
 * What DiscardDesignDraft needs: who is discarding the design's drafts.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Design\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class DiscardDesignDraftInput implements OperationInput
{
    public function __construct(public string $actorId) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
