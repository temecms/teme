<?php

/**
 * Carries on an import that stopped (I-332): the run runs again under a new
 * token, from the phase it stopped in, and every object already done is
 * skipped by the map. A person confirms their password first; the operator
 * doesn't.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\ImportEngine;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\ContinueImportRunInput;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class ContinueImportRun extends BaseOperation implements RequiresElevation
{
    use AuthorizesImports;

    public function requiresElevation(OperationInput $input): bool
    {
        return $input->actorId() !== null;
    }

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ContinueImportRunInput $input */
        if (! Str::isUlid($input->runId)) {
            throw new InvalidArgumentException('An import run needs a run ID.');
        }
    }

    /**
     * @return array{runId: string, token: string, actorId: string|null}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ContinueImportRunInput $input */
        $sourceId = ImportRun::query()->whereKey($input->runId)->value('source_id');

        if ($sourceId === null) {
            throw ValidationException::withMessages(['run' => 'Only an import that stopped can be carried on.']);
        }

        $source = ImportSource::query()->whereKey($sourceId)->lockForUpdate()->firstOrFail();
        $run = ImportRun::query()->whereKey($input->runId)->lockForUpdate()->firstOrFail();

        if ($run->state !== ImportRunState::Stopped) {
            throw ValidationException::withMessages(['run' => 'Only an import that stopped can be carried on.']);
        }

        if ($source->credential === null) {
            throw ValidationException::withMessages(['run' => 'Teme has forgotten this site’s application password. Connect the site again first.']);
        }

        if (ImportRun::query()->where('source_id', $source->id)->where('state', ImportRunState::Running->value)->exists()) {
            throw ValidationException::withMessages(['run' => 'This site is being imported already.']);
        }

        $token = strtolower((string) Str::ulid());
        $run->forceFill([
            'state' => ImportRunState::Running,
            'phase_token' => $token,
            'ended_at' => null,
            'stop_reason' => null,
            'stop_message' => null,
        ])->save();

        // Every failure is marked for this run again; it meets them as the run it continues would.
        ImportMapEntry::query()->where('source_id', $source->id)->where('status', ImportStatus::Failed->value)
            ->update(['status' => ImportStatus::NeedsUpdate->value]);

        app(SecurityLog::class)->record(SecurityEventType::ImportStarted, $input->actorId(), context: [
            'run' => (string) $run->id, 'source' => (string) $source->id, 'kind' => $run->kind->value, 'continued' => true,
        ]);

        return ['runId' => (string) $run->id, 'token' => $token, 'actorId' => $input->actorId()];
    }

    /**
     * After commit: the engine starts the phase the run stopped in.
     */
    protected function afterCommit(mixed $result): void
    {
        /** @var array{runId: string, token: string, actorId: string|null} $result */
        app(ImportEngine::class)->begin($result['runId'], $result['token'], JobActor::of($result['actorId']));
    }
}
