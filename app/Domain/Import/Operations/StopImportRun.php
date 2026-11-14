<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Concerns\TalliesRuns;
use App\Domain\Import\Operations\Input\StopImportRunInput;
use App\Domain\Import\Tracking\ImportRunJobs;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Stops a running import (I-332), by a person's word or a job's: stopped with its reason and words, its counts tallied, logged, and the page cache purged once. A job gives its attempt's token, and a stale one stops nothing.
 */
final class StopImportRun extends BaseOperation
{
    use AuthorizesImports;
    use TalliesRuns;

    /** The run this call stopped, for its tracked job after commit (I-1714). */
    private ?string $endedRun = null;

    /** Who stopped it. */
    private ?string $endedBy = null;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var StopImportRunInput $input */
        $ulid = $input->runId;

        if (! Str::isUlid($ulid)) {
            throw new InvalidArgumentException('An import run needs a run ID.');
        }
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var StopImportRunInput $input */
        $run = ImportRun::query()->whereKey($input->runId)->lockForUpdate()->first();
        $running = $run !== null && $run->state === ImportRunState::Running;

        if ($input->token !== null) {
            if (! $running || $run->phase_token !== $input->token) {
                return false;
            }
        } elseif (! $running) {
            throw ValidationException::withMessages(['run' => 'This import isn’t running.']);
        }

        $run->state = ImportRunState::Stopped;
        $run->phase_token = null;
        $run->ended_at = now();
        $run->stop_reason = $input->reason;
        $run->stop_message = $input->words ?? 'Stopped on request.';
        $this->tally($run);
        $run->save();

        app(SecurityLog::class)->record(SecurityEventType::ImportEnded, $input->actorId(), context: [
            'run' => (string) $run->id, 'source' => (string) $run->source_id, 'state' => 'stopped',
            'reason' => $input->reason,
            'created' => $run->created_count, 'updated' => $run->updated_count, 'skipped' => $run->skipped_count,
            'failed' => $run->failed_count, 'warnings' => $run->warning_count,
        ]);

        $this->purgeEverything();

        $this->endedRun = (string) $run->id;
        $this->endedBy = $input->actorId();

        return true;
    }

    /**
     * After commit: the run's tracked job fails with it, in the run's own words, unless a cancel ended it first.
     */
    protected function afterCommit(mixed $result): void
    {
        if ($this->endedRun !== null) {
            app(ImportRunJobs::class)->ended($this->endedRun, $this->endedBy);
        }
    }
}
