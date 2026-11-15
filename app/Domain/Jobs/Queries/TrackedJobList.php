<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Queries;

use App\Domain\Jobs\JobKinds;
use App\Domain\Jobs\JobState;
use App\Domain\Plugins\Jobs\PluginJobKind;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Queries\Cursor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Tools › Jobs' list (I-104, I-3445): every tracked job, newest first, a
 * keyset page at a time, each as the screen draws it with who started it
 * and, for a plugin job, the plugin's mark; the failed jobs Retry all
 * failed would retry; and a run's jobs. It writes nothing.
 *
 * @phpstan-type JobRow array{id: string, kind: string, label: string, owner: string, plugin: string|null, state: string, stateLabel: string, message: string|null, total: int|null, done: int, percent: int|null, startedAt: string, endedAt: string|null, startedBy: array{kind: string, name: string|null}, canRetry: bool, canCancel: bool}
 */
final readonly class TrackedJobList
{
    /** The most jobs a page holds. */
    public const int MOST = 200;

    /** Where the header's counts stop; it draws "99+" from here, as the inbox's unread count does. */
    public const int COUNT_CAP = 100;

    /** A moment as PostgreSQL writes one, which is what a cursor carries. */
    private const string MOMENT = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?[+-]\d{2}(:\d{2})?$/';

    /** A job's ID: 26 lower-case letters and digits (I-418). */
    private const string ID = '/^[0-9a-hjkmnp-tv-z]{26}$/';

    /** The columns a row is drawn from, with the starter's name now. */
    private const array COLUMNS = [
        'j.id', 'j.kind', 'j.owner', 'j.label', 'j.starter', 'j.state', 'j.total', 'j.done', 'j.message',
        'j.created_at', 'j.ended_at', 'u.name as starter_name',
    ];

    public function __construct(
        private JobKinds $kinds,
        private PluginRegistry $plugins,
    ) {}

    /**
     * Returns a page of jobs, newest first, at most $perPage (1 to 200); with
     * the cursor that opens the newer page, or null at the first, and the one
     * that opens the older page, or null at the last.
     *
     * @return array{rows: list<JobRow>, newer: string|null, older: string|null}
     */
    public function page(?string $after, ?string $before, int $perPage): array
    {
        $limit = max(1, min(self::MOST, $perPage));
        $back = self::cursor($before);
        $backwards = $back !== null;
        $cursor = $back ?? self::cursor($after);

        $query = DB::table('tracked_jobs as j')->leftJoin('users as u', 'u.id', '=', 'j.started_by')->select(self::COLUMNS);

        if ($cursor !== null) {
            // One row comparison, so the newest-first index answers it.
            $query->whereRaw('(j.created_at, j.id) '.($backwards ? '>' : '<').' (?::timestamptz, ?)', $cursor);
        }

        $order = $backwards ? 'asc' : 'desc';
        $found = $query->orderBy('j.created_at', $order)->orderBy('j.id', $order)->limit($limit + 1)->get();
        $more = $found->count() > $limit;
        $found = $found->take($limit);

        if ($backwards) {
            $found = $found->reverse()->values();
        }

        $first = $found->first();
        $last = $found->last();

        return [
            'rows' => $this->shown($found),
            'newer' => $first !== null && ($backwards ? $more : $cursor !== null) ? self::cursorAt($first) : null,
            'older' => $last !== null && ($backwards || $more) ? self::cursorAt($last) : null,
        ];
    }

    /**
     * Returns how many failed jobs could be retried now: those whose kind is
     * installed.
     */
    public function failedCount(): int
    {
        $installed = array_keys($this->kinds->all());

        return $installed === [] ? 0 : DB::table('tracked_jobs')->whereRaw("state = 'failed'")->whereIn('kind', $installed)->count();
    }

    /**
     * Returns the IDs of at most $most failed jobs that could be retried now,
     * newest first.
     *
     * @return list<string>
     */
    public function failedIds(int $most): array
    {
        $installed = array_keys($this->kinds->all());

        if ($installed === []) {
            return [];
        }

        return array_values(DB::table('tracked_jobs')
            ->whereRaw("state = 'failed'")
            ->whereIn('kind', $installed)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, $most))
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    /**
     * Returns a run's jobs, newest first, at most $most (1 to MOST), each as
     * Tools › Jobs draws it.
     *
     * @return list<JobRow>
     */
    public function ofRun(string $type, string $id, int $most = self::MOST): array
    {
        $found = DB::table('tracked_jobs as j')
            ->leftJoin('users as u', 'u.id', '=', 'j.started_by')
            ->select(self::COLUMNS)
            ->where('j.run_type', $type)
            ->where('j.run_id', $id)
            ->orderByDesc('j.created_at')
            ->orderByDesc('j.id')
            ->limit(max(1, min(self::MOST, $most)))
            ->get();

        return $this->shown($found);
    }

    /**
     * Returns one job as Tools › Jobs draws it, or null when there is none
     * with this ID.
     *
     * @return JobRow|null
     */
    public function one(string $id): ?array
    {
        $found = DB::table('tracked_jobs as j')
            ->leftJoin('users as u', 'u.id', '=', 'j.started_by')
            ->select(self::COLUMNS)
            ->where('j.id', $id)
            ->get();

        return $this->shown($found)[0] ?? null;
    }

    /**
     * Returns this person's own jobs, newest first, at most $most, each as
     * Tools › Jobs draws it.
     *
     * @return list<JobRow>
     */
    public function startedBy(int $userId, int $most): array
    {
        $found = DB::table('tracked_jobs as j')
            ->leftJoin('users as u', 'u.id', '=', 'j.started_by')
            ->select(self::COLUMNS)
            ->where('j.started_by', $userId)
            ->orderByDesc('j.created_at')
            ->orderByDesc('j.id')
            ->limit(max(1, min(self::MOST, $most)))
            ->get();

        return $this->shown($found);
    }

    /**
     * Returns how many of this person's jobs are waiting or running, how many
     * have failed and could be retried now, each counted up to COUNT_CAP, and
     * whether they have any job at all.
     *
     * @return array{running: int, failed: int, hasJobs: bool}
     */
    public function countsFor(int $userId): array
    {
        // Kind keys are lower-case words, hyphens and dots (JobKinds::KEY), so
        // they need no quoting inside an array literal.
        $installed = '{'.implode(',', array_keys($this->kinds->all())).'}';
        $cap = self::COUNT_CAP;

        // One statement; each part walks tracked_jobs_started_by_index over
        // this person's own jobs, so its cost is bounded by the jobs they have
        // kept, never by everyone's (I-104).
        $row = DB::selectOne(<<<SQL
            SELECT
                (SELECT count(*) FROM (SELECT 1 FROM tracked_jobs WHERE started_by = ? AND state IN ('waiting', 'running') LIMIT {$cap}) AS r) AS running,
                (SELECT count(*) FROM (SELECT 1 FROM tracked_jobs WHERE started_by = ? AND state = 'failed' AND kind = ANY (?::text[]) LIMIT {$cap}) AS f) AS failed,
                EXISTS (SELECT 1 FROM tracked_jobs WHERE started_by = ?) AS has_jobs
            SQL, [$userId, $userId, $installed, $userId]);

        return [
            'running' => (int) $row->running,
            'failed' => (int) $row->failed,
            'hasJobs' => (bool) $row->has_jobs,
        ];
    }

    /**
     * The moment and the ID a cursor carries, or null when it carries
     * anything else: a cursor someone edited never reaches PostgreSQL.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function cursor(?string $value): ?array
    {
        $at = Cursor::decode($value, 2);

        if ($at === null || ! is_string($at[0]) || ! is_string($at[1]) || preg_match(self::MOMENT, $at[0]) !== 1 || preg_match(self::ID, $at[1]) !== 1) {
            return null;
        }

        return [$at[0], $at[1]];
    }

    /**
     * The cursor that opens the page beyond this row.
     */
    private static function cursorAt(stdClass $row): string
    {
        return Cursor::encode([(string) $row->created_at, (string) $row->id]);
    }

    /**
     * The rows as the screen draws them.
     *
     * @param  Collection<int, stdClass>  $found
     *
     * @return list<JobRow>
     */
    private function shown(Collection $found): array
    {
        $installed = $this->kinds->all();
        $labels = $this->pluginLabels($found);
        $rows = [];

        foreach ($found as $row) {
            $state = JobState::from((string) $row->state);
            $total = $row->total === null ? null : (int) $row->total;
            $done = (int) $row->done;
            $owner = (string) $row->owner;
            $kind = (string) $row->kind;

            $rows[] = [
                'id' => (string) $row->id,
                'kind' => $kind,
                'label' => (string) $row->label,
                'owner' => $owner,
                'plugin' => $kind === PluginJobKind::KEY ? ($labels[$owner] ?? $owner) : null,
                'state' => $state->value,
                'stateLabel' => $state->label(),
                'message' => $row->message === null ? null : (string) $row->message,
                'total' => $total,
                'done' => $done,
                // Rounded down, so a job never shows 100% before it has ended (I-104).
                'percent' => $total === null || $total === 0 ? null : min(intdiv($done * 100, $total), $state->ended() ? 100 : 99),
                'startedAt' => CarbonImmutable::parse((string) $row->created_at)->utc()->toIso8601String(),
                'endedAt' => $row->ended_at === null ? null : CarbonImmutable::parse((string) $row->ended_at)->utc()->toIso8601String(),
                'startedBy' => match (true) {
                    $row->starter === 'operator' => ['kind' => 'operator', 'name' => null],
                    $row->starter_name === null => ['kind' => 'removed', 'name' => null],
                    default => ['kind' => 'person', 'name' => (string) $row->starter_name],
                },
                'canRetry' => $state === JobState::Failed && isset($installed[$kind]),
                'canCancel' => ! $state->ended(),
            ];
        }

        return $rows;
    }

    /**
     * Plugin labels keyed by plugin code, for the plugin-job rows on this page.
     *
     * @param  Collection<int, stdClass>  $found
     *
     * @return array<string, string>
     */
    private function pluginLabels(Collection $found): array
    {
        $codes = $found
            ->filter(fn (stdClass $row): bool => (string) $row->kind === PluginJobKind::KEY)
            ->map(fn (stdClass $row): string => (string) $row->owner)
            ->unique()
            ->values()
            ->all();

        if ($codes === []) {
            return [];
        }

        $labels = [];

        foreach (PluginRecord::query()->whereIn('code', $codes)->get(['name', 'code']) as $record) {
            $labels[$record->code] = $this->plugins->find($record->name)?->manifest?->label() ?? $record->name;
        }

        return $labels;
    }
}
