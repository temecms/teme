<?php

/**
 * Ends a run that went through every phase (I-332): finished, its counts tallied, logged, and the page cache purged once, after the run's last commit.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Concerns\TalliesRuns;
use App\Domain\Import\Operations\Input\FinishImportRunInput;
use App\Domain\Import\Tracking\ImportRunJobs;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

final class FinishImportRun extends BaseOperation
{
    use AuthorizesImports;
    use TalliesRuns;

    /** The run this call finished, for its tracked job after commit (I-1714). */
    private ?string $endedRun = null;

    /** Who finished it. */
    private ?string $endedBy = null;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var FinishImportRunInput $input */
        $ulid = $input->runId;

        if (! Str::isUlid($ulid)) {
            throw new InvalidArgumentException('An import run needs a run ID.');
        }
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var FinishImportRunInput $input */
        $run = ImportRun::query()->whereKey($input->runId)->lockForUpdate()->first();

        if ($run === null || $run->state !== ImportRunState::Running || $run->phase_token !== $input->fromToken) {
            return false;
        }

        $run->state = ImportRunState::Finished;
        $run->phase = null;
        $run->phase_token = null;
        $run->ended_at = now();
        $this->tally($run);
        $run->save();

        app(SecurityLog::class)->record(SecurityEventType::ImportEnded, $input->actorId(), context: [
            'run' => (string) $run->id, 'source' => (string) $run->source_id, 'state' => 'finished',
            'created' => $run->created_count, 'updated' => $run->updated_count, 'skipped' => $run->skipped_count,
            'failed' => $run->failed_count, 'warnings' => $run->warning_count,
        ]);

        $this->purgeEverything();

        $this->endedRun = (string) $run->id;
        $this->endedBy = $input->actorId();

        return true;
    }

    /**
     * After commit: the run's tracked job finishes with it.
     */
    protected function afterCommit(mixed $result): void
    {
        if ($this->endedRun !== null) {
            app(ImportRunJobs::class)->ended($this->endedRun, $this->endedBy);
        }
    }
}
