<?php

/**
 * Records that one object could not be imported (I-332): its map row as failed, keeping the Teme ID and hash an earlier run gave it, and its one error. Does nothing for a stopped attempt's token.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportMessageLevel;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Concerns\LocksObjects;
use App\Domain\Import\Operations\Input\RecordImportFailureInput;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

final class RecordImportFailure extends BaseOperation
{
    use AuthorizesImports;
    use LocksObjects;

    public function __construct(private readonly ImportMap $map) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordImportFailureInput $input */
        $ulid = $input->runId;

        if (! Str::isUlid($ulid)) {
            throw new InvalidArgumentException('An import run needs a run ID.');
        }
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var RecordImportFailureInput $input */
        $run = ImportRun::query()->whereKey($input->runId)->sharedLock()->first();

        if ($run === null || $run->state !== ImportRunState::Running || $run->phase_token !== $input->token) {
            return false;
        }

        $this->lockObject((string) $run->source_id, $input->ref);
        $now = now();
        $entry = $this->map->entry((string) $run->source_id, $input->ref)
            ?? new ImportMapEntry(['source_id' => $run->source_id, 'object_type' => $input->ref->type, 'object_id' => $input->ref->id]);

        $entry->fill([
            'status' => ImportStatus::Failed,
            'outcome' => ImportOutcome::Failed,
            'run_id' => $run->id,
            'imported_at' => $now,
        ])->save();

        ImportMessageRecord::query()->where('map_id', $entry->id)->delete();
        ImportMessageRecord::query()->create([
            'run_id' => $run->id, 'map_id' => $entry->id, 'level' => ImportMessageLevel::Error, 'code' => $input->why->code,
            'message' => $input->why->message, 'context' => $input->why->context, 'created_at' => $now,
        ]);

        return true;
    }
}
