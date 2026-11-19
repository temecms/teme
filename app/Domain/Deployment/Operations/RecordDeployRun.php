<?php

/**
 * Records one teme:deploy that ran (I-?? draft): the release, how it ended,
 * the backup it took and its whole report. The operator only. Returns the
 * row's ID.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment\Operations;

use App\Domain\Deployment\Operations\Input\RecordDeployRunInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Domain\Updates\TemeVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class RecordDeployRun extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        if ($input->actorId() !== null || ! Operator::isPresent()) {
            throw new AuthorizationException('Only the operator records a deploy.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordDeployRunInput $input */
        if ($input->report->check) {
            throw ValidationException::withMessages(['report' => ['teme:deploy --check changes nothing, so it isn’t recorded.']]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var RecordDeployRunInput $input */
        $report = $input->report;
        $id = strtolower((string) Str::ulid());

        // The deploy that brings the column runs its preflight before migrating, and must still record itself.
        $tagged = Schema::hasColumn('deploy_runs', 'tagged') ? ['tagged' => (bool) config('teme.tagged')] : [];

        DB::table('deploy_runs')->insert([
            'id' => $id,
            'version' => TemeVersion::current(),
            ...$tagged,
            'revision' => config('teme.revision'),
            'outcome' => $report->outcome->value,
            'exit_code' => $report->outcome->exitCode(),
            'sentence' => $report->sentence,
            'backup_run_id' => $report->backupRunId(),
            'report' => json_encode($report->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            'started_at' => $input->startedAt,
            'finished_at' => $input->finishedAt,
        ]);

        return $id;
    }
}
