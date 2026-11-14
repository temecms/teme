<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `StopImportRun` needs: the run, who stops it, the attempt's token when a job stops it, and why.
 */
final readonly class StopImportRunInput implements OperationInput
{
    public function __construct(
        public string $runId,
        public ?string $actorId,
        public ?string $token = null,
        public string $reason = 'by-hand',
        public ?string $words = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
