<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\MarkAuthorsWaitingInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Marks for the content phase the posts the import credited to the fallback
 * owner (I-2998), once a run has made a person or first matched one: their
 * map rows become work again, so the next full run gives each to its author
 * if they came. Only an import's actor may call it.
 */
final class MarkAuthorsWaiting extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var MarkAuthorsWaitingInput $input */
        if (! ImportSource::query()->whereKey($input->sourceId)->exists()) {
            throw ValidationException::withMessages(['source' => 'The import source no longer exists.']);
        }

        if (! ImportRun::query()->whereKey($input->runId)->where('source_id', $input->sourceId)->exists()) {
            throw ValidationException::withMessages(['run' => 'That run isn’t one of this source’s.']);
        }
    }

    protected function perform(OperationInput $input): int
    {
        /** @var MarkAuthorsWaitingInput $input */
        $arrived = ImportMapEntry::query()
            ->where('source_id', $input->sourceId)
            ->where('object_type', 'user')
            ->where('run_id', $input->runId)
            ->whereNotNull('teme_id')
            ->where(fn (Builder $person) => $person->where('outcome', ImportOutcome::Created->value)
                ->orWhereRaw("facts->>'matched_in' = ?", [$input->runId]))
            ->exists();

        if (! $arrived) {
            return 0;
        }

        return ImportMapEntry::query()
            ->where('source_id', $input->sourceId)
            ->where('object_type', 'post')
            ->where('status', ImportStatus::Imported->value)
            ->whereExists(fn (QueryBuilder $said) => $said->selectRaw('1')->from('import_messages')
                ->whereColumn('import_messages.map_id', 'import_map.id')
                ->where('import_messages.code', 'post.author-missing'))
            ->update(['status' => ImportStatus::NeedsUpdate->value]);
    }
}
