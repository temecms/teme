<?php

declare(strict_types=1);

namespace App\Domain\Import\Queries;

use App\Domain\Import\ImportMessageLevel;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Phases\ImportPhase;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Tracking\ImportRunJobs;
use Illuminate\Support\Facades\DB;

/**
 * What Tools › Import draws (I-227): the sites Teme imports from, where each stands, a run with its steps and counts, and, for a report, a run's objects by kind and outcome, its messages and its two files. It writes nothing.
 */
final readonly class ImportScreen
{
    /** A site's state, with the words people read. */
    public const array STATES = ['none' => 'Not imported yet', 'running' => 'Importing', 'stopped' => 'Stopped', 'finished' => 'Imported'];

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

    public function __construct(private ImportRuns $runs, private ImportPhases $phases, private ImportRunJobs $tracking) {}

    /**
     * Returns the sites Teme knows, newest first: each with where it stands, whether its password is forgotten, its latest import's start and the address of its page.
     *
     * @return list<array{id: string, address: string, host: string, state: string, stateLabel: string, forgotten: bool, lastImportAt: string|null, href: string}>
     */
    public function sites(): array
    {
        $sites = [];

        foreach (ImportSource::query()->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get() as $source) {
            $latest = $this->runs->latest((string) $source->id);
            $state = $latest === null ? 'none' : $latest->state->value;

            $sites[] = [
                'id' => (string) $source->id,
                'address' => $source->address,
                'host' => self::host($source->address),
                'state' => $state,
                'stateLabel' => self::STATES[$state],
                'forgotten' => $source->forgotten_at !== null,
                'lastImportAt' => $latest?->started_at->utc()->toIso8601String(),
                'href' => route('tools.importer.show', ['source' => (string) $source->id], false),
            ];
        }

        return $sites;
    }

    /**
     * Returns one site as its page draws it: its address, who it is connected as, when it was last probed and whether its password is forgotten; null when Teme has no such site.
     *
     * @return array{id: string, address: string, host: string, userName: string|null, pluginVersion: string, connectedAt: string, forgotten: bool}|null
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
            'connectedAt' => $source->probed_at->utc()->toIso8601String(),
            'forgotten' => $source->forgotten_at !== null,
        ];
    }

    /**
     * Returns the step a site's page opens at (I-227): Connect while its
     * password is forgotten and nothing runs; Analyze and Choose before its
     * first import; Import while its latest import runs or has stopped;
     * Report once that has finished. Null when Teme has no such site.
     */
    public function step(string $sourceId): ?string
    {
        $source = ImportSource::query()->find($sourceId);

        if ($source === null) {
            return null;
        }

        $latest = $this->runs->latest($sourceId);

        return match (true) {
            $source->forgotten_at !== null && $latest?->state !== ImportRunState::Running => 'connect',
            $latest === null => 'plan',
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
     * @return array{id: string, kind: string, kindLabel: string, state: string, stateLabel: string, startedAt: string, endedAt: string|null, stopMessage: string|null, counts: array{created: int, updated: int, skipped: int, failed: int, warnings: int}, steps: list<array{key: string, label: string, state: string}>}|null
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
     * Returns an import's report: its objects by kind and outcome, its messages grouped by code (errors first), whether more codes exist than are listed, how many errors need attention, and whether it is ready to switch (null until it has finished and the address check exists). Null when Teme has no such import.
     *
     * @return array{types: list<array{type: string, label: string, created: int, updated: int, skipped: int, failed: int}>, messages: list<array{code: string, level: string, levelLabel: string, words: string, count: int}>, more: bool, problems: int, ready: bool|null}|null
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

        $types = [];
        $known = [];

        foreach (self::TYPES as [$type, $label]) {
            $known[] = $type;

            if (isset($counted[$type])) {
                $types[] = self::typeRow($type, $label, $counted[$type]);
            }
        }

        $others = array_diff(array_keys($counted), $known);
        sort($others);

        foreach ($others as $type) {
            $types[] = self::typeRow((string) $type, (string) $type, $counted[$type]);
        }

        $codes = DB::table('import_messages')
            ->where('run_id', $runId)
            ->selectRaw('level, code, count(*) AS total, (array_agg(message ORDER BY created_at, id))[1] AS words')
            ->groupBy('level', 'code')
            ->orderByRaw("(level = 'error') DESC, count(*) DESC, code")
            ->limit(self::CODES + 1)
            ->get();

        $messages = [];

        foreach ($codes->take(self::CODES) as $row) {
            $messages[] = [
                'code' => (string) $row->code,
                'level' => (string) $row->level,
                'levelLabel' => $row->level === ImportMessageLevel::Error->value ? 'Error' : 'Warning',
                'words' => (string) $row->words,
                'count' => (int) $row->total,
            ];
        }

        $problems = DB::table('import_messages')->where('run_id', $runId)->where('level', ImportMessageLevel::Error->value)->count();
        $check = $this->phases->find('check');

        return [
            'types' => $types,
            'messages' => $messages,
            'more' => $codes->count() > self::CODES,
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
     * @return array{type: string, label: string, created: int, updated: int, skipped: int, failed: int}
     */
    private static function typeRow(string $type, string $label, array $outcomes): array
    {
        return [
            'type' => $type,
            'label' => $label,
            'created' => $outcomes[ImportOutcome::Created->value] ?? 0,
            'updated' => $outcomes[ImportOutcome::Updated->value] ?? 0,
            'skipped' => $outcomes[ImportOutcome::Skipped->value] ?? 0,
            'failed' => $outcomes[ImportOutcome::Failed->value] ?? 0,
        ];
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
        $steps = $this->tracking->steps($run->kind);
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

    private static function host(string $address): string
    {
        return (string) (parse_url($address, PHP_URL_HOST) ?: $address);
    }
}
