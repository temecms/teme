<?php

declare(strict_types=1);

namespace App\Domain\Updates\Operations;

use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Domain\Updates\Operations\Input\RecordTemeUpdateStepInput;
use App\Domain\Updates\Requests\UpdateStatus;
use App\Domain\Updates\Requests\UpdateStep;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Records where one step of a running update stands (I-3326): its state, a sentence, and the backup and
 * deploy run it brought about. The operator only: the updater.
 */
final class RecordTemeUpdateStep extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        if ($input->actorId() !== null || ! Operator::isPresent()) {
            throw new AuthorizationException('Only the updater, as the operator, records an update’s steps.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordTemeUpdateStepInput $input */
        if (! in_array($input->state, UpdateStep::STATES, true)) {
            throw ValidationException::withMessages(['state' => ["{$input->state} isn’t a state a step is recorded in."]]);
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var RecordTemeUpdateStepInput $input */
        $row = DB::table('teme_updates')->where('id', $input->updateId)->lockForUpdate()->first();

        if ($row === null || $row->status !== UpdateStatus::Running->value) {
            throw ValidationException::withMessages(['update' => ['There’s no such update.']]);
        }

        $now = CarbonImmutable::now()->toIso8601String();
        $steps = json_decode((string) $row->steps, true);
        $steps = array_map(function (array $entry) use ($input, $now): array {
            if ($entry['step'] !== $input->step->value) {
                return $entry;
            }

            // A step recorded started again (a count of probes) keeps the start it had.
            $restarted = $input->state === UpdateStep::STARTED && $entry['state'] !== UpdateStep::STARTED;
            $entry['state'] = $input->state;
            $entry['detail'] = $input->detail;
            $entry['startedAt'] = $restarted ? $now : $entry['startedAt'];
            $entry['asks'] = $input->asks ?? $entry['asks'] ?? 0;
            $entry['runsAfter'] = $input->runsAfter ?? $entry['runsAfter'] ?? null;
            $entry['mark'] = $input->mark ?? $entry['mark'] ?? null;
            $entry['finishedAt'] = in_array($input->state, [UpdateStep::DONE, UpdateStep::FAILED, UpdateStep::SWITCHED_BACK], true) ? $now : null;

            return $entry;
        }, is_array($steps) ? $steps : []);

        DB::table('teme_updates')->where('id', $input->updateId)->update(array_filter([
            'step' => $input->step->value,
            'steps' => json_encode($steps, JSON_THROW_ON_ERROR),
            'backup_run_id' => $input->backupRunId,
            'deploy_run_id' => $input->deployRunId,
        ], fn (mixed $value): bool => $value !== null));

        return null;
    }
}
