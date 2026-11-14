<?php

declare(strict_types=1);

namespace App\Domain\Deployment;

use App\Domain\Deployment\Operations\Input\RecordDeployRunInput;
use App\Domain\Deployment\Operations\RecordDeployRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Records each teme:deploy that ran through RecordDeployRun (I-?? draft).
 * A record that can't be written never changes the deploy's outcome: the
 * failure is logged and the deploy's exit code stands.
 */
final readonly class DeployHistory
{
    public function __construct(private RecordDeployRun $record) {}

    /**
     * Returns the record's ID, or null when nothing was recorded.
     */
    public function record(DeployReport $report, CarbonImmutable $startedAt): ?string
    {
        if ($report->check) {
            return null;
        }

        try {
            // A deploy refused before the release that makes the table had migrated.
            if (! Schema::hasTable('deploy_runs')) {
                return null;
            }

            return $this->record->execute(new RecordDeployRunInput($report, $startedAt, CarbonImmutable::now()));
        } catch (Throwable $failed) {
            report($failed);

            return null;
        }
    }
}
