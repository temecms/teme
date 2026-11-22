<?php

/**
 * Imports one object of the old site (I-332): its phase's import() and its
 * map row and messages, in one transaction, so a failure leaves neither.
 * The run's row is read FOR SHARE and its token checked, so a stop waits
 * for objects in flight and a stopped attempt's leftovers write nothing;
 * the object is locked before its row is read again, so two jobs that
 * reach it import it once.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Concerns\LocksObjects;
use App\Domain\Import\Operations\Input\ImportObjectInput;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\ListPhase;
use App\Domain\Import\Phases\Terms\TermsPhase;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Support\Operations\BaseOperation;
use Teme\Contracts\Operations\OperationInput;

final class ImportObject extends BaseOperation
{
    use AuthorizesImports;
    use LocksObjects;

    public function __construct(private readonly ImportPhases $phases, private readonly ImportMap $map) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): ?ImportOutcome
    {
        /** @var ImportObjectInput $input */
        $run = ImportRun::query()->whereKey($input->context->runId)->sharedLock()->first();
        $phase = $this->phases->find($input->phase);

        if ($run === null || $run->state !== ImportRunState::Running || $run->phase_token !== $input->token || ! $phase instanceof ListPhase) {
            return null;
        }

        $this->lockObject($run->source_id, $input->ref);
        $entry = $this->map->entry($run->source_id, $input->ref);

        if (! ImportMap::wants($entry, $input->hash)) {
            return null;
        }

        $result = $phase->import($input->ref, $input->item, $input->context, $input->prepared);
        $now = now();

        // The terms phase moves an export file's rows to and from this object (AdoptImportedTerm), so its row is read again.
        if ($phase instanceof TermsPhase) {
            $entry = $this->map->entry($run->source_id, $input->ref);
        }

        $entry ??= new ImportMapEntry(['source_id' => $run->source_id, 'object_type' => $input->ref->type, 'object_id' => $input->ref->id]);
        $entry->fill([
            // A skip keeps what an earlier run made of the object.
            'teme_type' => $result->temeType ?? $entry->teme_type,
            'teme_id' => $result->temeId ?? $entry->teme_id,
            'status' => $result->outcome->status(),
            'outcome' => $result->outcome,
            'source_hash' => $input->hash,
            'facts' => [...$entry->facts, ...$result->facts],
            'run_id' => $run->id,
            'imported_at' => $now,
        ])->save();

        ImportMessageRecord::query()->where('map_id', $entry->id)->delete();

        foreach ($result->messages as $message) {
            ImportMessageRecord::query()->create([
                'run_id' => $run->id, 'map_id' => $entry->id, 'level' => $message->level, 'code' => $message->code,
                'message' => $message->message, 'context' => $message->context, 'created_at' => $now,
            ]);
        }

        return $result->outcome;
    }
}
