<?php

/**
 * Starts a picture pass (#716): after an import that brought the articles
 * first, a run of its own that brings every media file, the ones posts use
 * first, and swaps each post's outside pictures for the library's as their
 * files arrive. It reads what the import read, with the import's choices
 * and lanes, and starts by itself when that import finishes; one runs per
 * site at a time, as any import does.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportEngine;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\StartMediaPassInput;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Queries\ImportRuns;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class StartMediaPass extends BaseOperation
{
    use AuthorizesImports;

    public function __construct(private readonly ImportPhases $phases, private readonly ImportRuns $runs) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        assert($input instanceof StartMediaPassInput);

        if (! Str::isUlid($input->runId)) {
            throw ValidationException::withMessages(['run' => 'Teme has no such import.']);
        }
    }

    /**
     * @return array{runId: string, token: string, actorId: string|null}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var StartMediaPassInput $input */
        $parent = ImportRun::query()->whereKey($input->runId)->first();

        if ($parent === null || $parent->kind === ImportRunKind::MediaPass || ($parent->choices['media'] ?? null) !== 'later') {
            throw ValidationException::withMessages(['run' => 'Only an import that brought the articles first has pictures to bring after.']);
        }

        if ($parent->state !== ImportRunState::Finished) {
            throw ValidationException::withMessages(['run' => 'The pictures come once the articles’ import has finished.']);
        }

        // One run per site at a time, as StartImportRun keeps it.
        ImportSource::query()->whereKey($parent->source_id)->lockForUpdate()->firstOrFail();

        if ($this->runs->running((string) $parent->source_id) !== null) {
            throw ValidationException::withMessages(['source' => 'This site is being imported already.']);
        }

        $token = strtolower((string) Str::ulid());
        $run = ImportRun::query()->create([
            'source_id' => $parent->source_id,
            'file_id' => $parent->file_id,
            'follows_run_id' => $parent->id,
            'kind' => ImportRunKind::MediaPass,
            'state' => ImportRunState::Running,
            'phase' => $this->phases->first(ImportRunKind::MediaPass)?->key(),
            'phase_token' => $token,
            // The import's own pause, lanes and choices: the pass reads what it read.
            'options' => $parent->options,
            'choices' => $parent->choices,
            'high_water_at' => now(),
            'started_by' => $input->actorId() === null ? null : (int) $input->actorId(),
            'started_at' => now(),
        ]);

        // A file an earlier pass couldn't bring, failed or skipped as the old site refused it, is tried again.
        ImportMapEntry::query()->where('source_id', $parent->source_id)->where('object_type', 'attachment')->whereNull('teme_id')
            ->whereIn('status', [ImportStatus::Failed->value, ImportStatus::Skipped->value])
            ->update(['status' => ImportStatus::NeedsUpdate->value]);

        app(SecurityLog::class)->record(SecurityEventType::ImportStarted, $input->actorId(), context: [
            'run' => (string) $run->id, 'source' => (string) $parent->source_id, 'kind' => ImportRunKind::MediaPass->value, 'continued' => false, 'follows' => (string) $parent->id,
        ]);

        return ['runId' => (string) $run->id, 'token' => $token, 'actorId' => $input->actorId()];
    }

    /**
     * After commit: the engine starts the pass, its jobs acting for whoever started it.
     */
    protected function afterCommit(mixed $result): void
    {
        /** @var array{runId: string, token: string, actorId: string|null} $result */
        app(ImportEngine::class)->begin($result['runId'], $result['token'], JobActor::of($result['actorId']));
    }
}
