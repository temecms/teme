<?php

/**
 * Clears a step's own run messages as it starts again (I-345): those of a
 * running run with no object, whose code starts with the step's word, so a
 * run carried on lists each once, and what was mended disappears.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\ClearRunMessagesInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

final class ClearRunMessages extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ClearRunMessagesInput $input */
        if (! Str::isUlid($input->runId) || preg_match('/^[a-z][a-z0-9-]*\.$/', $input->prefix) !== 1) {
            throw new InvalidArgumentException('Clearing a run’s messages needs its ID and a code’s first word, with its dot.');
        }
    }

    protected function perform(OperationInput $input): int
    {
        /** @var ClearRunMessagesInput $input */
        $run = ImportRun::query()->whereKey($input->runId)->sharedLock()->first();

        if ($run === null || $run->state !== ImportRunState::Running) {
            return 0;
        }

        return (int) ImportMessageRecord::query()
            ->where('run_id', $run->id)
            ->whereNull('map_id')
            ->where('code', 'like', $input->prefix.'%')
            ->delete();
    }
}
