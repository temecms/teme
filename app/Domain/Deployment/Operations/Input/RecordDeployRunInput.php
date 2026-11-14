<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Operations\Input;

use App\Domain\Deployment\DeployReport;
use Carbon\CarbonImmutable;
use Teme\Contracts\Operations\OperationInput;

/**
 * What RecordDeployRun needs: the deploy's report and when it started and finished. Only the operator runs it, so the actor is none.
 */
final readonly class RecordDeployRunInput implements OperationInput
{
    public function __construct(
        public DeployReport $report,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $finishedAt,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
