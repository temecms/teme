<?php

/**
 * Marks for the content phase the posts and pages whose media was missing
 * (I-338), once a run's media phase has brought media: their map rows
 * become work again, so the same run converts them with what came. Only
 * an import's actor may call it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\MarkContentWaitingInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class MarkContentWaiting extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var MarkContentWaitingInput $input */
        if (! ImportSource::query()->whereKey($input->sourceId)->exists()) {
            throw ValidationException::withMessages(['source' => 'The import source no longer exists.']);
        }

        if (! ImportRun::query()->whereKey($input->runId)->where('source_id', $input->sourceId)->exists()) {
            throw ValidationException::withMessages(['run' => 'That run isn’t one of this source’s.']);
        }
    }

    protected function perform(OperationInput $input): int
    {
        /** @var MarkContentWaitingInput $input */
        $brought = ImportMapEntry::query()
            ->where('source_id', $input->sourceId)
            ->where('object_type', 'attachment')
            ->where('run_id', $input->runId)
            ->whereIn('outcome', [ImportOutcome::Created->value, ImportOutcome::Updated->value])
            ->whereNotNull('teme_id')
            ->exists();

        if (! $brought) {
            return 0;
        }

        return ImportMapEntry::query()
            ->where('source_id', $input->sourceId)
            ->where('object_type', 'post')
            // A reusable block's row is skipped (it becomes no item), and its content may wait for media too.
            ->whereIn('status', [ImportStatus::Imported->value, ImportStatus::Skipped->value])
            ->whereRaw('facts @> ?::jsonb', ['{"missing": true}'])
            ->update(['status' => ImportStatus::NeedsUpdate->value]);
    }
}
