<?php

/**
 * What `ContinueImportRun` needs: the stopped run, and who carries it on (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ContinueImportRunInput implements OperationInput
{
    public function __construct(
        public string $runId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
