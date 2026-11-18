<?php

/**
 * What RecordTemeUpdateStep needs: the request, the step, the state it is now in, a sentence about it, the backup and deploy run it brought about, how many times the step has asked the platform, the moment after which a deploy run counts for it, the newest deployment the platform listed before the step asked, and the compose file the service had before the step replaced it, which a switch back puts back. Only the operator, the updater, runs it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Operations\Input;

use App\Domain\Updates\Requests\UpdateStep;
use Teme\Contracts\Operations\OperationInput;

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
        public ?string $compose = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
