<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `FinishImportRun` needs: the run, the token of its last phase, and who acts.
 */
final readonly class FinishImportRunInput implements OperationInput
{
    public function __construct(
        public string $runId,
        public string $fromToken,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
