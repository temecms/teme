<?php

declare(strict_types=1);

namespace App\Domain\Updates\Operations\Input;

use App\Domain\Updates\Requests\UpdateStep;
use Teme\Contracts\Operations\OperationInput;

/**
 * What RecordTemeUpdateStep needs: the request, the step, the state it is now in, a sentence about it, the backup and deploy run it brought about, how many times the step has asked the platform, the moment after which a deploy run counts for it, and the newest deployment the platform listed before the step asked. Only the operator, the updater, runs it.
 */
final readonly class RecordTemeUpdateStepInput implements OperationInput
{
    public function __construct(
        public string $updateId,
        public UpdateStep $step,
        public string $state,
        public ?string $detail = null,
        public ?string $backupRunId = null,
        public ?string $deployRunId = null,
        public ?int $asks = null,
        public ?string $runsAfter = null,
        public ?string $mark = null,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
