<?php

/**
 * Starts an import of a site Teme keeps (I-332): a full run, or a catch-up
 * of what changed since the last finished one. One runs per site at
 * a time; every failure the site has is marked for the run, which tries it
 * again when it meets it: a full import meets everything, a catch-up only
 * what changed on WordPress. A person confirms
 * their password first; the operator doesn't. After commit the
 * engine starts the run's first phase, its jobs acting for whoever started
 * it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
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
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Queries\ImportRuns;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class StartImportRun extends BaseOperation implements RequiresElevation
{
    use AuthorizesImports;

    /** The options a run takes, with their defaults: the pause between pages, to spare the old site. */
    public const array OPTIONS = ['pause_ms' => 1000];

    public function __construct(private readonly ImportPhases $phases, private readonly ImportRuns $runs) {}

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
        assert($input instanceof StartImportRunInput);

        foreach (array_keys($input->options) as $key) {
            if (! array_key_exists($key, self::OPTIONS)) {
                throw ValidationException::withMessages(['options' => "Teme has no import option called {$key}."]);
            }
        }

        $pause = $input->options['pause_ms'] ?? self::OPTIONS['pause_ms'];

        if (! is_int($pause) || $pause < 0 || $pause > 60000) {
            throw ValidationException::withMessages(['options.pause_ms' => 'Choose a pause from 0 to 60,000 milliseconds.']);
        }

        if (($input->choices !== [] && array_is_list($input->choices)) || strlen((string) json_encode($input->choices)) > 65536) {
            throw ValidationException::withMessages(['choices' => 'The import’s choices couldn’t be read.']);
        }
    }

    /**
     * @return array{runId: string, token: string, actorId: string|null}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var StartImportRunInput $input */
        $source = ImportSource::query()->whereKey($input->sourceId)->lockForUpdate()->firstOrFail();

        if ($source->credential === null) {
            throw ValidationException::withMessages(['source' => 'Teme has forgotten this site’s application password. Connect the site again first.']);
        }

        if ($this->runs->running((string) $source->id) !== null) {
            throw ValidationException::withMessages(['source' => 'This site is being imported already.']);
        }

        if ($input->kind === ImportRunKind::CatchUp && $this->runs->lastFinished((string) $source->id) === null) {
            throw ValidationException::withMessages(['kind' => 'A catch-up needs a finished import of this site first.']);
        }

        $token = strtolower((string) Str::ulid());
        $run = ImportRun::query()->create([
            'source_id' => $source->id,
            'kind' => $input->kind,
            'state' => ImportRunState::Running,
            'phase' => $this->phases->first($input->kind)?->key(),
            'phase_token' => $token,
            'options' => [...self::OPTIONS, ...$input->options],
            'choices' => $input->choices,
            'high_water_at' => now(),
            'started_by' => $input->actorId() === null ? null : (int) $input->actorId(),
            'started_at' => now(),
        ]);

        // Every failure is marked for this run; a full import meets them all, a catch-up only what changed.
        ImportMapEntry::query()->where('source_id', $source->id)->where('status', ImportStatus::Failed->value)
            ->update(['status' => ImportStatus::NeedsUpdate->value]);

        app(SecurityLog::class)->record(SecurityEventType::ImportStarted, $input->actorId(), context: [
            'run' => (string) $run->id, 'source' => (string) $source->id, 'kind' => $input->kind->value, 'continued' => false,
        ]);

        return ['runId' => (string) $run->id, 'token' => $token, 'actorId' => $input->actorId()];
    }

    /**
     * After commit: the engine starts the run, its jobs acting for whoever
     * started it, the operator made here, at the shell (I-392).
     */
    protected function afterCommit(mixed $result): void
    {
        /** @var array{runId: string, token: string, actorId: string|null} $result */
        app(ImportEngine::class)->begin($result['runId'], $result['token'], JobActor::of($result['actorId']));
    }
}
