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

use App\Domain\Import\File\FileAddresses;
use App\Domain\Import\ImportEngine;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Queries\ImportRuns;
use App\Domain\Import\Source\FileReader;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;
use Teme\Contracts\Settings\SettingsReader;

final class StartImportRun extends BaseOperation implements RequiresElevation
{
    use AuthorizesImports;

    /** The options a run takes, with their defaults: the pause between pages, to spare the old site. */
    public const array OPTIONS = ['pause_ms' => 1000];

    /**
     * The options a run of an export file also takes, with none stored by
     * default: the media files fetched at once, the media setting's when
     * none is given (#701).
     *
     * @since next
     */
    public const array FILE_OPTIONS = ['media_lanes'];

    public function __construct(private readonly ImportPhases $phases, private readonly ImportRuns $runs, private readonly SettingsReader $settings) {}

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
            if (! array_key_exists($key, self::OPTIONS) && ! in_array($key, self::FILE_OPTIONS, true)) {
                throw ValidationException::withMessages(['options' => "Teme has no import option called {$key}."]);
            }
        }

        $pause = $input->options['pause_ms'] ?? self::OPTIONS['pause_ms'];

        if (! is_int($pause) || $pause < 0 || $pause > 60000) {
            throw ValidationException::withMessages(['options.pause_ms' => 'Choose a pause from 0 to 60,000 milliseconds.']);
        }

        $ceiling = $this->downloads();
        $lanes = $input->options['media_lanes'] ?? 1;

        if (! is_int($lanes) || $lanes < 1 || $lanes > $ceiling) {
            throw ValidationException::withMessages(['options.media_lanes' => "Choose from 1 to {$ceiling} files at once."]);
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

        // A run of an uploaded file reads the file, never the companion (docs/architecture/import.md).
        $file = $input->fileId === null ? null : ImportFile::query()->whereKey($input->fileId)->lockForUpdate()->first();

        if ($input->fileId !== null && ($file === null || $file->source_id !== $source->id || $file->state !== 'ready' || $file->deleted_at !== null)) {
            throw ValidationException::withMessages(['file' => 'Teme no longer has that export file. Upload it again.']);
        }

        if ($file !== null && $input->kind !== ImportRunKind::Full) {
            throw ValidationException::withMessages(['kind' => 'An export file is imported whole: upload a newer file to bring what changed.']);
        }

        if ($file === null && $source->credential === null) {
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
            'file_id' => $file?->id,
            'kind' => $input->kind,
            'state' => ImportRunState::Running,
            'phase' => $this->phases->first($input->kind)?->key(),
            'phase_token' => $token,
            // A file's media lanes are frozen here, so a change to the setting never moves a running import's lists (#701).
            'options' => [...self::OPTIONS, ...($file === null ? [] : ['media_lanes' => $this->downloads()]), ...$input->options],
            'choices' => $input->choices,
            'high_water_at' => now(),
            'started_by' => $input->actorId() === null ? null : (int) $input->actorId(),
            'started_at' => now(),
        ]);

        // The old site's addresses, from the bases chosen, for the address steps and the check.
        if ($file !== null) {
            FileAddresses::write((string) $file->id, FileReader::basesOf($input->choices), (new FileReader((string) $file->id, (string) $source->id))->aliases());
        }

        // Every failure is marked for this run; a full import meets them all, a catch-up only what changed.
        ImportMapEntry::query()->where('source_id', $source->id)->where('status', ImportStatus::Failed->value)
            ->update(['status' => ImportStatus::NeedsUpdate->value]);

        app(SecurityLog::class)->record(SecurityEventType::ImportStarted, $input->actorId(), context: [
            'run' => (string) $run->id, 'source' => (string) $source->id, 'kind' => $input->kind->value, 'continued' => false, ...($file === null ? [] : ['file' => (string) $file->id]),
        ]);

        return ['runId' => (string) $run->id, 'token' => $token, 'actorId' => $input->actorId()];
    }

    /**
     * The most media files an import of an export file fetches at once: the media setting.
     */
    private function downloads(): int
    {
        return $this->settings->int('media', 'import_downloads');
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
