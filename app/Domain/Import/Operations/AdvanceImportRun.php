<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Concerns\TalliesRuns;
use App\Domain\Import\Operations\Input\AdvanceImportRunInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Moves a running import on to its next phase (I-332): checks the attempt's token under the run's lock, so two callbacks can't both advance, and tallies the counts so far.
 */
final class AdvanceImportRun extends BaseOperation
{
    use AuthorizesImports;
    use TalliesRuns;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var AdvanceImportRunInput $input */
        $ulid = $input->runId;

        if (! Str::isUlid($ulid)) {
            throw new InvalidArgumentException('An import run needs a run ID.');
        }
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var AdvanceImportRunInput $input */
        $run = ImportRun::query()->whereKey($input->runId)->lockForUpdate()->first();

        if ($run === null || $run->state !== ImportRunState::Running || $run->phase_token !== $input->fromToken) {
            return false;
        }

        $run->phase = $input->toPhase;
        $run->phase_token = $input->toToken;
        $this->tally($run);
        $run->save();

        return true;
    }
}
