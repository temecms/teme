<?php

/**
 * What RebuildCommentStats needs: who is importing (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RebuildCommentStatsInput implements OperationInput
{
    public function __construct(public ?string $actorId) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
