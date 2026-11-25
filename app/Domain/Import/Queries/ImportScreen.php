<?php

/**
 * What Tools › Import draws (I-227): the sites Teme imports from, where each stands, a run with its steps and counts, and, for a report, a run's objects by kind and outcome, its messages and its two files. It writes nothing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Queries;

use App\Domain\Import\ImportMessageLevel;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Phases\ImportPhase;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\Settings\SiteSettingsStep;
use App\Domain\Import\PluginData\PluginDataLists;
use App\Domain\Import\PluginData\PluginDataReport;
use App\Domain\Import\Source\SourceReaders;
use App\Domain\Import\Tracking\ImportRunJobs;
use Illuminate\Support\Facades\DB;

final readonly class ImportScreen
{
    /** A site's state, with the words people read. */
    public const array STATES = ['none' => 'Connected', 'running' => 'Importing', 'stopped' => 'Stopped', 'finished' => 'Imported'];

    /**
     * The kinds of object a report names, in its order, with their labels
     * (I-345): pairs, never keyed by a type's name.
     */
    public const array TYPES = [
        ['user', 'People'],
        ['term', 'Categories and tags'],
        ['attachment', 'Media files'],
        ['outside-image', 'Images from other sites'],
        ['post', 'Posts and pages'],
        ['comment', 'Comments'],
        ['menu', 'Menus'],
        ['menu-item', 'Menu items'],
        ['redirect', 'Redirects'],
        ['setting', 'Settings'],
        ['file', 'File addresses'],
    ];

    /** How many message codes a report lists; the full report has every one. */
    private const int CODES = 50;

    /** How many objects one statement of a file reads. */
    private const int BATCH = 500;

    /** The map's object type of posts and pages, whose outside pictures a picture pass swaps (#716). */
    private const string POSTS = 'post';

    public function __construct(private ImportRuns $runs, private ImportPhases $phases, private ImportRunJobs $tracking) {}

    /**
     * Returns the sites Teme knows, newest first: each with where it stands, the Teme Companion version it last reported, whether its password is forgotten, its latest import's start and the address of its page.
     *
     * @return list<array{id: string, address: string, host: string, state: string, stateLabel: string, pluginVersion: string, forgotten: bool, lastImportAt: string|null, href: string, kind: 'companion'|'file', uploadedAt: string|null}>
     */
    public function sites(): array
    {
        $sites = [];
        $files = app(ImportFiles::class);

        foreach (ImportSource::query()->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get() as $source) {
            $latest = $this->runs->latest((string) $source->id);
            $state = $latest === null ? 'none' : $latest->state->value;
            $export = $files->forSource((string) $source->id);

            $sites[] = [
                'id' => (string) $source->id,
                'address' => $source->address,
                'host' => self::host($source->address),
                'state' => $state,
                'stateLabel' => __(self::STATES[$state]),
                'pluginVersion' => $source->plugin_version,
                'forgotten' => $source->forgotten_at !== null,
                'lastImportAt' => $latest?->started_at->utc()->toIso8601String(),
                'href' => route('tools.importer.show', ['source' => (string) $source->id], false),
                'kind' => $export === null ? 'companion' : 'file',
                'uploadedAt' => $export['uploadedAt'] ?? null,
            ];
        }

        return $sites;
    }

    /**
     * Returns one site as its page draws it: its address, who it is connected as, when it was last probed and whether its password is forgotten, and the export file it reads from now, if any; null when Teme has no such site. A site never connected live has no user name, version or connection date.
     *
     * @return array{id: string, address: string, host: string, userName: string|null, pluginVersion: string|null, connectedAt: string|null, forgotten: bool, connected: bool, file: array{id: string, name: string, sizeBytes: int, madeAt: string|null, uploadedAt: string, deletesOn: string|null}|null, otherHosts: list<string>, suggestedHosts: list<array{host: string, links: int}>}|null
     */
    public function site(string $sourceId): ?array
    {
        $source = ImportSource::query()->find($sourceId);

        if ($source === null) {
            return null;
        }

        return [
            'id' => (string) $source->id,
            'address' => $source->address,
            'host' => self::host($source->address),
            'userName' => $source->user_name,
            'pluginVersion' => $source->plugin_version,
            'connectedAt' => $source->probed_at?->utc()->toIso8601String(),
            'forgotten' => $source->forgotten_at !== null,
            'connected' => $source->api_root !== null,
            'file' => $this->file($source),
            'otherHosts' => array_values(array_filter((array) ($source->other_hosts ?? []), is_string(...))),
            'suggestedHosts' => $source->forgotten_at !== null || $source->credential === null
                ? []
                : app(SuggestedHosts::class)->for($source),
        ];
    }

    /**
     * Returns the step a site's page opens at (I-227): Connect while its
     * password is forgotten and nothing runs; Analyze and Choose before its
     * first import, and before an import of a newer export file; Import
     * while its latest import runs or has stopped; Report once that has
     * finished. A site never connected live with no file left is at
     * Connect. Null when Teme has no such site.
     */
    public function step(string $sourceId): ?string
    {
        $source = ImportSource::query()->find($sourceId);

        if ($source === null) {
            return null;
        }

        $latest = $this->runs->latest($sourceId);
        $file = app(SourceReaders::class)->fileOf($source);
        // A file read after the latest import began waits for its own.
        $newerFile = $file !== null && $latest !== null && $latest->state !== ImportRunState::Running && $file->id !== $latest->file_id && $file->created_at->greaterThan($latest->started_at);

        return match (true) {
            $source->forgotten_at !== null && $latest?->state !== ImportRunState::Running => 'connect',
            $source->api_root === null && $file === null && $latest === null => 'connect',
            $latest === null, $newerFile => 'plan',
            $latest->state === ImportRunState::Finished => 'report',
            default => 'import',
        };
    }

    /**
     * Returns the ID of the site's latest import, or null when it has had none.
     */
    public function latestRun(string $sourceId): ?string
    {
        $latest = $this->runs->latest($sourceId);

        return $latest === null ? null : (string) $latest->id;
    }

    /**
     * Returns one import as its page draws it: its kind and state, when it started and ended, why it stopped, its counts and its steps; null when Teme has no such import.
     *
     * An import that brought the articles first also has its picture pass:
     * how it stands, the files it brought, the posts still showing pictures
     * from the old site, and whether a pass may start again (#716).
     *
     * @return array{id: string, kind: string, kindLabel: string, state: string, stateLabel: string, startedAt: string, endedAt: string|null, stopMessage: string|null, counts: array{created: int, updated: int, skipped: int, failed: int, warnings: int}, steps: list<array{key: string, label: string, state: string}>, file: array{id: string, name: string, sizeBytes: int, uploadedAt: string, deletesOn: string|null, deleted: bool}|null, picturePass: array{id: string|null, state: string|null, stateLabel: string|null, href: string|null, files: int, waiting: int, again: bool}|null}|null
     */
    public function run(string $runId): ?array
    {
        $run = ImportRun::query()->find($runId);

        if ($run === null) {
            return null;
        }

        return [
            'id' => (string) $run->id,
            'kind' => $run->kind->value,
            'kindLabel' => $run->kind->label(),
            'state' => $run->state->value,
            'stateLabel' => $run->state->label(),
            'startedAt' => $run->started_at->utc()->toIso8601String(),
            'endedAt' => $run->ended_at?->utc()->toIso8601String(),
            'stopMessage' => $run->stop_message,
            'counts' => $this->counts($run),
            'steps' => $this->steps($run),
            'file' => $this->runFile($run),
            'picturePass' => $this->picturePass($run),
        ];
    }

    /**
     * The picture pass that follows an import that brought the articles
     * first, as its page shows it under the import; null for any other run.
     *
     * @return array{id: string|null, state: string|null, stateLabel: string|null, href: string|null, files: int, waiting: int, again: bool}|null
     */
    private function picturePass(ImportRun $run): ?array
    {
        if ($run->kind === ImportRunKind::MediaPass || ($run->choices['media'] ?? null) !== 'later') {
            return null;
        }

        $pass = $this->runs->passOf((string) $run->id);
        $waiting = DB::table('import_map')
            ->where('source_id', $run->source_id)->where('object_type', self::POSTS)->whereNotNull('teme_id')
            ->whereRaw("(facts -> 'waiting_media') <> '[]'::jsonb")
            ->count();
        $again = $run->state === ImportRunState::Finished && $waiting > 0 && $this->runs->running((string) $run->source_id) === null;

        return [
            'id' => $pass === null ? null : (string) $pass->id,
            'state' => $pass?->state->value,
            'stateLabel' => $pass?->state->label(),
            'href' => $pass === null ? null : route('tools.importer.show', ['source' => (string) $run->source_id, 'run' => (string) $pass->id], false),
            'files' => $pass === null ? 0 : $this->counts($pass)['created'],
            'waiting' => $waiting,
            'again' => $again,
        ];
    }

    /**
     * Returns the ID of the site a run belongs to, or null when Teme has no such run.
     */
    public function sourceOf(string $runId): ?string
    {
        $source = ImportRun::query()->whereKey($runId)->value('source_id');

        return $source === null ? null : (string) $source;
    }

    /**
     * Returns the choices and options of the site's last finished import, which a catch-up runs with, or null when none has finished.
     *
     * @return array{choices: array<string, mixed>, options: array<string, mixed>}|null
     */
    public function catchUpWith(string $sourceId): ?array
    {
        $run = $this->runs->lastFinished($sourceId);

        return $run === null ? null : ['choices' => $run->choices, 'options' => $run->options];
    }

    /**
     * Returns the site's imports, newest first and at most 20: each with its kind, state, start and end, its created and failed counts and the address of its report.
     *
     * @return list<array{id: string, kind: string, kindLabel: string, state: string, stateLabel: string, startedAt: string, endedAt: string|null, counts: array{created: int, failed: int}, href: string}>
     */
    public function runs(string $sourceId): array
    {
        $rows = [];

        foreach (ImportRun::query()->where('source_id', $sourceId)->orderByDesc('started_at')->orderByDesc('id')->limit(20)->get() as $run) {
            $counts = $this->counts($run);

            $rows[] = [
                'id' => (string) $run->id,
                'kind' => $run->kind->value,
                'kindLabel' => $run->kind->label(),
                'state' => $run->state->value,
                'stateLabel' => $run->state->label(),
                'startedAt' => $run->started_at->utc()->toIso8601String(),
                'endedAt' => $run->ended_at?->utc()->toIso8601String(),
                'counts' => ['created' => $counts['created'], 'failed' => $counts['failed']],
                'href' => route('tools.importer.show', ['source' => $sourceId, 'run' => (string) $run->id], false),
            ];
        }

        return $rows;
    }

    /**
     * Returns an import's report: its objects by kind and outcome, its messages grouped by code (errors first) with a plain group name and sentence, whether more codes exist than are listed, how many errors need attention, and whether it is ready to switch (null until it has finished and the address check exists). Null when Teme has no such import.
     *
     * @return array{types: list<array{type: string, label: string, created: int, updated: int, skipped: int, failed: int, warnings: int|null}>, messages: list<array{code: string, level: string, levelLabel: string, words: string, count: int, group: string, sentence: string|null}>, more: bool, levels: array{error: int, warning: int}, problems: int, ready: bool|null}|null
     */
    public function report(string $runId): ?array
    {
        $run = ImportRun::query()->find($runId);

        if ($run === null) {
            return null;
        }

        $counted = [];

        foreach (DB::table('import_map')->where('run_id', $runId)->selectRaw('object_type, outcome, count(*) AS total')->groupBy('object_type', 'outcome')->get() as $row) {
            $counted[(string) $row->object_type][(string) $row->outcome] = (int) $row->total;
        }

        // Each plugin's records get a row of their own (docs/proposals/plugin-import-socket.md).
        $plugins = isset($counted[PluginDataLists::TYPE]) ? app(PluginDataReport::class)->rows($runId) : [];
        unset($counted[PluginDataLists::TYPE]);
        $warned = self::warnedByType($runId);
        $types = [];
        $known = [];

        foreach (self::TYPES as [$type, $label]) {
            $known[] = $type;

            if (isset($counted[$type])) {
                $types[] = self::typeRow($type, __($label), $counted[$type], $warned[$type] ?? 0);
            }
        }

        $others = array_diff(array_keys($counted), $known);
        sort($others);

        foreach ($others as $type) {
            $types[] = self::typeRow((string) $type, (string) $type, $counted[$type], $warned[$type] ?? 0);
        }

        // A plugin's records are counted under one type for every plugin, so their warnings can't be told apart.
        foreach ($plugins as $plugin) {
            $types[] = [...$plugin, 'warnings' => null];
        }

        $levels = DB::table('import_messages')->where('run_id', $runId)->selectRaw('level, count(*) AS total')->groupBy('level')->pluck('total', 'level');

        $codes = DB::table('import_messages')
            ->where('run_id', $runId)
            ->selectRaw('level, code, count(*) AS total, (array_agg(message ORDER BY created_at, id))[1] AS words')
            ->groupBy('level', 'code')
            // An empty front page first: it is the home page readers meet.
            ->orderByRaw("(code = ?) DESC, (level = 'error') DESC, count(*) DESC, code", [SiteSettingsStep::FRONT_EMPTY])
            ->limit(self::CODES + 1)
            ->get();

        // Fold codes that share a plain name (and every error into Failed), summing counts.
        $grouped = [];

        foreach ($codes->take(self::CODES) as $row) {
            $named = ImportMessageGroups::for((string) $row->code);
            $error = $row->level === ImportMessageLevel::Error->value;
            $group = $error ? __('Failed') : $named['group'];
            $sentence = $error
                ? __('These items failed and need attention.')
                : $named['sentence'];

            if (isset($grouped[$group])) {
                $grouped[$group]['count'] += (int) $row->total;

                continue;
            }

            $grouped[$group] = [
                'code' => (string) $row->code,
                'level' => (string) $row->level,
                'levelLabel' => $error ? 'Error' : 'Warning',
                'words' => (string) $row->words,
                'count' => (int) $row->total,
                'group' => $group,
                'sentence' => $sentence,
            ];
        }

        $messages = array_values($grouped);

        $problems = DB::table('import_messages')->where('run_id', $runId)->where('level', ImportMessageLevel::Error->value)->count();
        $check = $this->phases->find('check');

        return [
            'types' => $types,
            'messages' => $messages,
            'more' => $codes->count() > self::CODES,
            'levels' => ['error' => (int) ($levels[ImportMessageLevel::Error->value] ?? 0), 'warning' => (int) ($levels[ImportMessageLevel::Warning->value] ?? 0)],
            'problems' => $problems,
            'ready' => $run->state === ImportRunState::Finished && $check !== null && $check->runsIn($run->kind) ? $problems === 0 : null,
        ];
    }

    /**
     * Yields a report file's lines, each ending in a newline: the header, a line for each object of the run (only those that failed or have a message when $attentionOnly), then each message of the run's own.
     *
     * @return iterable<string>
     */
    public function lines(string $runId, bool $attentionOnly): iterable
    {
        yield "type,wordpress_id,outcome,teme_type,teme_id,messages\n";

        $after = '';

        while (true) {
            $batch = DB::table('import_map')
                ->where('run_id', $runId)
                ->where('id', '>', $after)
                ->when($attentionOnly, fn ($query) => $query->where(fn ($either) => $either
                    ->where('outcome', ImportOutcome::Failed->value)
                    ->orWhereExists(fn ($messages) => $messages->selectRaw('1')->from('import_messages')->whereColumn('import_messages.map_id', 'import_map.id'))))
                ->orderBy('id')
                ->limit(self::BATCH)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            $words = [];

            foreach (DB::table('import_messages')->whereIn('map_id', $batch->pluck('id'))->orderBy('created_at')->orderBy('id')->get(['map_id', 'message']) as $message) {
                $words[(string) $message->map_id][] = (string) $message->message;
            }

            foreach ($batch as $row) {
                yield implode(',', array_map(self::cell(...), [
                    (string) $row->object_type,
                    (string) $row->object_id,
                    (string) $row->outcome,
                    (string) $row->teme_type,
                    (string) $row->teme_id,
                    implode(' | ', $words[(string) $row->id] ?? []),
                ]))."\n";
            }

            $after = (string) $batch->last()->id;
        }

        foreach (DB::table('import_messages')->where('run_id', $runId)->whereNull('map_id')->orderBy('created_at')->orderBy('id')->pluck('message') as $message) {
            yield 'run,,,,,'.self::cell((string) $message)."\n";
        }
    }

    /**
     * Returns the name of a report file, `import-{host}-{Y-m-d}.csv` (or `…-attention.csv`) by the day the import started in UTC; null when Teme has no such import.
     */
    public function fileName(string $runId, bool $attentionOnly): ?string
    {
        $run = ImportRun::query()->with('source')->find($runId);

        if ($run === null) {
            return null;
        }

        return 'import-'.self::host($run->source->address).'-'.$run->started_at->utc()->format('Y-m-d').($attentionOnly ? '-attention' : '').'.csv';
    }

    /**
     * One kind's row of the table of what came across.
     *
     * @param  array<string, int>  $outcomes
     *
     * @return array{type: string, label: string, created: int, updated: int, skipped: int, failed: int, warnings: int}
     */
    private static function typeRow(string $type, string $label, array $outcomes, int $warnings): array
    {
        return [
            'type' => $type,
            'label' => $label,
            'created' => $outcomes[ImportOutcome::Created->value] ?? 0,
            'updated' => $outcomes[ImportOutcome::Updated->value] ?? 0,
            'skipped' => $outcomes[ImportOutcome::Skipped->value] ?? 0,
            'failed' => $outcomes[ImportOutcome::Failed->value] ?? 0,
            'warnings' => $warnings,
        ];
    }

    /**
     * How many objects of each type have at least one warning in this run.
     *
     * @return array<string, int>
     */
    private static function warnedByType(string $runId): array
    {
        return DB::table('import_messages as x')
            ->join('import_map as m', 'm.id', '=', 'x.map_id')
            ->where('x.run_id', $runId)
            ->where('x.level', ImportMessageLevel::Warning->value)
            ->selectRaw('m.object_type, count(DISTINCT x.map_id) AS total')
            ->groupBy('m.object_type')
            ->pluck('total', 'object_type')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * One CSV cell: a `'` before a first character a spreadsheet would run
     * as a formula, then quoted when it holds a comma, a quote or a line
     * break, its quotes doubled, as the redirects' export writes them.
     */
    private static function cell(string $value): string
    {
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            $value = "'".$value;
        }

        return strpbrk($value, ",\"\r\n") === false ? $value : '"'.str_replace('"', '""', $value).'"';
    }

    /**
     * The run's counts: its columns once it has ended, else counted from the
     * map and its messages as the run's end tallies them.
     *
     * @return array{created: int, updated: int, skipped: int, failed: int, warnings: int}
     */
    private function counts(ImportRun $run): array
    {
        if ($run->state !== ImportRunState::Running) {
            return [
                'created' => $run->created_count,
                'updated' => $run->updated_count,
                'skipped' => $run->skipped_count,
                'failed' => $run->failed_count,
                'warnings' => $run->warning_count,
            ];
        }

        $counted = DB::table('import_map')->where('run_id', $run->id)
            ->selectRaw('outcome, count(*) AS total')->groupBy('outcome')
            ->pluck('total', 'outcome');

        return [
            'created' => (int) ($counted[ImportOutcome::Created->value] ?? 0),
            'updated' => (int) ($counted[ImportOutcome::Updated->value] ?? 0),
            'skipped' => (int) ($counted[ImportOutcome::Skipped->value] ?? 0),
            'failed' => (int) ($counted[ImportOutcome::Failed->value] ?? 0),
            'warnings' => DB::table('import_messages')->where('run_id', $run->id)->where('level', ImportMessageLevel::Warning->value)->count(),
        ];
    }

    /**
     * The steps the run goes through: those before its phase done, its phase
     * now, the rest to come; all done once it has finished.
     *
     * @return list<array{key: string, label: string, state: string}>
     */
    private function steps(ImportRun $run): array
    {
        $steps = $this->tracking->steps($run->kind, $run->file_id !== null);
        $position = array_search($run->phase, array_map(fn (ImportPhase $phase): string => $phase->key(), $steps), true);
        $rows = [];

        foreach ($steps as $index => $step) {
            $state = match (true) {
                $run->state === ImportRunState::Finished => 'done',
                $position === false => 'next',
                $index < $position => 'done',
                $index === $position => 'now',
                default => 'next',
            };

            $rows[] = ['key' => $step->key(), 'label' => $step->label(), 'state' => $state];
        }

        return $rows;
    }

    /**
     * The export file the site reads from now, as the plan's head says it.
     *
     * @return array{id: string, name: string, sizeBytes: int, madeAt: string|null, uploadedAt: string, deletesOn: string|null}|null
     */
    private function file(ImportSource $source): ?array
    {
        $file = app(SourceReaders::class)->fileOf($source);

        return $file === null ? null : [
            'id' => (string) $file->id,
            'name' => $file->name,
            'sizeBytes' => (int) $file->size_bytes,
            'madeAt' => $file->made_at?->utc()->toIso8601String(),
            'uploadedAt' => $file->created_at->utc()->toIso8601String(),
            'deletesOn' => app(ImportFiles::class)->deletesOn((string) $file->id),
        ];
    }

    /**
     * The export file a run read, deleted or not; null for a run of the companion.
     *
     * @return array{id: string, name: string, sizeBytes: int, uploadedAt: string, deletesOn: string|null, deleted: bool}|null
     */
    private function runFile(ImportRun $run): ?array
    {
        $file = $run->file_id === null ? null : ImportFile::query()->find($run->file_id);

        return $file === null ? null : [
            'id' => (string) $file->id,
            'name' => $file->name,
            'sizeBytes' => (int) $file->size_bytes,
            'uploadedAt' => $file->created_at->utc()->toIso8601String(),
            'deletesOn' => app(ImportFiles::class)->deletesOn((string) $file->id),
            'deleted' => $file->deleted_at !== null,
        ];
    }

    private static function host(string $address): string
    {
        return (string) (parse_url($address, PHP_URL_HOST) ?: $address);
    }
}
