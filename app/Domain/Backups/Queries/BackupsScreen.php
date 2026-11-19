<?php

/**
 * Every row Tools › Backups draws (I-364): where setup stands, the places,
 * the newest backup, what runs now, the latest checks and the recovery keys,
 * read from backup_destinations, backup_runs and backup_keys, never from
 * restic and never a credential.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Queries;

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\BackupVerdict;
use App\Domain\Backups\BackupWords;
use App\Domain\Backups\KeptRuns;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupKey;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\RecoveryKeyChallenge;
use App\Domain\Backups\RecoveryKeyChallengeId;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\SftpKeyPair;
use App\Domain\Backups\Tools\SnapshotRun;
use App\Domain\Settings\Models\SettingsGroupRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;
use Teme\Contracts\Settings\SettingsReader;

final readonly class BackupsScreen
{
    /**
     * The most backups the screen lists; teme:backup:list has every one.
     *
     * @since next
     */
    public const int MOST_LISTED = 50;

    /** The kinds of run that check a backup. */
    public const array CHECK_KINDS = ['check', 'read-back', 'test-restore'];

    /** Each check kind's name in a sentence. */
    public const array KIND_LABELS = [
        'check' => 'The check after the backup',
        'read-back' => 'The nightly read-back',
        'test-restore' => 'The weekly test restore',
    ];

    /** What a passed check says when it recorded no message of its own. */
    private const array PASSED_SENTENCES = [
        'check' => 'The backup is all there, reads back as written, and has no damage.',
        'read-back' => 'A seventh of the backups was read back, and every part matched.',
        'test-restore' => 'The newest backup was restored into a spare database, and its rows and 50 media files matched.',
    ];

    public function __construct(
        private BackupSetup $setup,
        private BackupPlaces $places,
        private BackupStatus $status,
        private BackupWords $words,
        private SettingsReader $settings,
        private DueBackupWork $due,
    ) {}

    /**
     * Where setup stands: `none` (no place in use), `unfinished` (a place,
     * and no recovery key typed back, with that place), `paused` (a restore's
     * places wait for an answer) or `set-up`.
     *
     * @return array{state: string, placeId: string|null, placeName: string|null}
     */
    public function setupState(): array
    {
        if ($this->setup->isFinished()) {
            // A restore's places wait for an answer before anything backs up (docs/architecture/backups.md).
            $state = $this->places->isPaused() ? 'paused' : 'set-up';

            return ['state' => $state, 'placeId' => null, 'placeName' => null];
        }

        $place = $this->setup->unfinishedPlace();

        return $place === null
            ? ['state' => 'none', 'placeId' => null, 'placeName' => null]
            : ['state' => 'unfinished', 'placeId' => $place->id, 'placeName' => $place->name];
    }

    /**
     * The newest run of each check kind at each place in use (and with no
     * place) that has ended, newest first. A whole read-back or test-restore
     * run that has a step per place is left out, since its steps carry the
     * same sentence; one with no step (it stopped before any place) is listed.
     *
     * @param  Collection<int, BackupDestination>  $places
     *
     * @return Collection<int, BackupRun>
     */
    public function latestChecks(Collection $places): Collection
    {
        // DISTINCT ON keeps the first row of each (kind, place) in this order: the newest.
        return BackupRun::query()
            ->selectRaw('DISTINCT ON (kind, destination_id) *')
            ->whereIn('kind', self::CHECK_KINDS)
            ->where('state', '<>', BackupRunState::Running->value)
            ->whereNotExists(fn ($steps) => $steps->selectRaw('1')->from('backup_runs as step')->whereColumn('step.parent_id', 'backup_runs.id'))
            ->where(fn ($query) => $query->whereNull('destination_id')->orWhereIn('destination_id', $places->pluck('id')->all()))
            ->orderBy('kind')
            ->orderBy('destination_id')
            ->orderByDesc('started_at')
            ->get()
            ->sortByDesc(fn (BackupRun $run): int => $run->started_at->getTimestamp())
            ->values();
    }

    /**
     * The newest whole backup that finished, or null.
     */
    public function newestFinished(): ?BackupRun
    {
        return $this->newestBackup(BackupRunState::Finished);
    }

    /**
     * The newest whole backup in one of $states, through backup_runs_kind_index.
     */
    private function newestBackup(BackupRunState ...$states): ?BackupRun
    {
        return BackupRun::query()
            ->where('kind', BackupRunKind::Backup->value)
            ->whereNull('parent_id')
            ->whereIn('state', array_map(fn (BackupRunState $state): string => $state->value, $states))
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * The line for a whole backup or prune running now, through backup_runs_running_index; null when none runs.
     */
    public function running(CarbonImmutable $now): ?string
    {
        $run = BackupRun::query()
            ->where('state', BackupRunState::Running->value)
            ->whereNull('parent_id')
            ->whereIn('kind', [BackupRunKind::Backup->value, BackupRunKind::Prune->value])
            ->orderByDesc('started_at')
            ->first();

        if ($run === null) {
            return null;
        }

        $when = $this->words->when($run->started_at, $now);

        if ($run->kind === BackupRunKind::Prune) {
            return "Removing old backups now. This started {$when}.";
        }

        $place = $run->destination_id === null ? null : BackupDestination::query()->whereKey($run->destination_id)->value('name');

        return $place === null
            ? "A backup is running now. It started {$when}."
            : "A backup is running now, to {$place}. It started {$when}.";
    }

    /**
     * Each place's newest backup: the newest finished backup it took, or the
     * newest finished copy to it, counted by the backup it copied; by place ID.
     *
     * @param  list<string>  $placeIds
     *
     * @return array<string, CarbonImmutable>
     */
    private function newestThere(array $placeIds): array
    {
        if ($placeIds === []) {
            return [];
        }

        // backup_runs_destination_index serves the inner filter; a copy's parent is its backup.
        $rows = DB::select(<<<'SQL'
            SELECT DISTINCT ON (run.destination_id) run.destination_id AS place, COALESCE(parent.started_at, run.started_at) AS made_at
            FROM backup_runs AS run
            LEFT JOIN backup_runs AS parent ON parent.id = run.parent_id
            WHERE run.destination_id = ANY (?::char(26)[])
              AND run.state = 'finished'
              AND run.kind IN ('backup', 'copy')
            ORDER BY run.destination_id, made_at DESC
            SQL, ['{'.implode(',', $placeIds).'}']);

        $newest = [];

        foreach ($rows as $row) {
            $newest[(string) $row->place] = CarbonImmutable::parse((string) $row->made_at);
        }

        return $newest;
    }

    /**
     * The session's challenge while every key it waits for is still pending
     * there (removed_at equal to added_at), as the screen offers it; else null.
     *
     * @param  array<string, mixed>|null  $challenge
     *
     * @return array{purpose: string, label: string, positions: array{int, int}}|null
     */
    public function pending(?array $challenge): ?array
    {
        if ($challenge === null) {
            return null;
        }

        try {
            $read = RecoveryKeyChallenge::fromArray($challenge);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($read->pending === []) {
            return null;
        }

        $waiting = BackupKey::query()
            ->where('role', BackupKeyRole::Recovery->value)
            ->whereColumn('removed_at', 'added_at')
            ->where(function ($query) use ($read): void {
                foreach ($read->pending as $placeId => $resticKeyId) {
                    $query->orWhere(fn ($pair) => $pair->where('destination_id', $placeId)->where('restic_key_id', $resticKeyId));
                }
            })
            ->count();

        return $waiting === count($read->pending)
            ? ['purpose' => $read->purpose->value, 'label' => $read->label, 'positions' => $read->positions, 'challengeId' => RecoveryKeyChallengeId::of($read)]
            : null;
    }

    /** The props data() returns, `running` included. */
    public const array KEYS = ['setup', 'running', 'firstBackup', 'firstBackupOnItsWay', 'verdict', 'newest', 'schedule', 'places', 'backups', 'checks', 'keys', 'pending', 'offServerAdvice'];

    /**
     * Whether the site's first backup is really on its way, so the open page
     * may ask for it: backups are set up, none has finished, and a backup is
     * running, or a job for one waits, or none has started and the nightly one
     * is due now. A first backup that failed, a pause and an hour that is
     * hours off all answer false.
     *
     * @param  array{state: string}  $setup
     * @param  array{state: string}|null  $job
     */
    public function firstBackupOnItsWay(array $setup, ?string $running, ?array $job, CarbonImmutable $now): bool
    {
        if ($setup['state'] !== 'set-up' || $this->newestFinished() !== null) {
            return false;
        }

        if ($running !== null || in_array($job['state'] ?? null, ['waiting', 'running'], true)) {
            return true;
        }

        return $this->newestBackup(BackupRunState::Running, BackupRunState::Failed, BackupRunState::Finished) === null
            && $this->due->backupDue($now);
    }

    /**
     * What Back up now may do now (I-361). Returns `hidden` until backups
     * are set up, `paused` while a restore's places wait, `busy` while a
     * backup job waits or runs or a backup or prune runs, otherwise `offered`.
     *
     * @param  array{state: string, placeId: string|null, placeName: string|null}  $setup
     * @param  array{state: string}|null  $job
     */
    public function backUpNow(array $setup, ?string $running, ?array $job): string
    {
        return match (true) {
            in_array($setup['state'], ['none', 'unfinished'], true) => 'hidden',
            $setup['state'] === 'paused' => 'paused',
            $running !== null || in_array($job['state'] ?? null, ['waiting', 'running'], true) => 'busy',
            default => 'offered',
        };
    }

    /**
     * Every prop of tools/backups/index.
     *
     * @param  array<string, mixed>|null  $challenge  the session's recovery key challenge, if any
     *
     * @return array<string, mixed>
     */
    public function data(CarbonImmutable $now, ?array $challenge): array
    {
        $setup = $this->setupState();
        $isSetUp = $setup['state'] === 'set-up';
        $places = $this->setup->placesInUse();
        $newest = $this->newestFinished();
        $newestThere = $this->newestThere($places->pluck('id')->all());
        $labels = $this->setup->recoveryKeyLabels();
        $verdict = $this->verdict($now);
        $missing = [];

        foreach ($places as $place) {
            $missing[$place->id] = $this->setup->labelsMissingAt($place);
        }

        return [
            'setup' => $setup,
            'running' => $this->running($now),
            'firstBackupOnItsWay' => $this->firstBackupOnItsWay($setup, $this->running($now), null, $now),
            'firstBackup' => $isSetUp && $newest === null ? $this->firstBackup($verdict, $now) : null,
            'verdict' => $verdict,
            'newest' => $newest === null ? null : $this->newestFigures($newest, $now),
            'schedule' => $isSetUp ? $this->schedule($now) : null,
            'places' => $places->map(fn (BackupDestination $place): array => $this->placeRow($place, $now, $newestThere, $missing[$place->id]))->all(),
            'backups' => $isSetUp ? $this->kept($places) : [],
            'checks' => $this->latestChecks($places)->map(fn (BackupRun $run): array => $this->checkRow($run, $now))->all(),
            'keys' => array_map(
                fn (string $label): array => [
                    'label' => $label,
                    'missingAt' => $places->filter(fn (BackupDestination $place): bool => in_array($label, $missing[$place->id], true))->pluck('name')->values()->all(),
                ],
                $labels,
            ),
            'pending' => $this->pending($challenge),
            'offServerAdvice' => $places->isNotEmpty() && ! $places->contains(fn (BackupDestination $place): bool => $place->kind->isOffServer()),
        ];
    }

    /**
     * 1.12e's verdict as the screen leads with it, or null while backups
     * aren't set up or are paused, which lead with their own words.
     *
     * @return array{level: string, sentence: string, more: list<string>}|null
     */
    public function verdict(CarbonImmutable $now): ?array
    {
        if ($this->setupState()['state'] !== 'set-up') {
            return null;
        }

        return $this->verdictLine($this->status->verdict($now), $this->setup->placesInUse(), $now);
    }

    /**
     * One verdict as a lead sentence and the lines under it: 1.12e's own
     * sentences when red or amber, word for word (I-2409); this screen's when green.
     *
     * @param  Collection<int, BackupDestination>  $places
     *
     * @return array{level: string, sentence: string, more: list<string>}
     */
    public function verdictLine(BackupVerdict $verdict, Collection $places, CarbonImmutable $now): array
    {
        if ($verdict->level() === BackupVerdict::RED) {
            return ['level' => 'red', 'sentence' => $verdict->problems[0], 'more' => [...array_slice($verdict->problems, 1), ...$verdict->cautions]];
        }

        if ($verdict->level() === BackupVerdict::AMBER) {
            return ['level' => 'amber', 'sentence' => $verdict->cautions[0], 'more' => array_slice($verdict->cautions, 1)];
        }

        $here = $places->contains(fn (BackupDestination $place): bool => ! $place->kind->isOffServer());
        $away = $places->contains(fn (BackupDestination $place): bool => $place->kind->isOffServer());
        $where = match (true) {
            $here && $away => 'on this server and off it',
            $away => 'off this server',
            default => 'on this server',
        };
        $when = $verdict->lastGoodBackup === null ? '' : ' '.$this->words->when($verdict->lastGoodBackup, $now);

        return ['level' => 'green', 'sentence' => "Backed up{$when}, {$where}, and checked.", 'more' => []];
    }

    /**
     * What a set-up site with no finished backup is told: when the first
     * starts and how to back up now; the command alone under an amber
     * verdict, which says when, and nothing under a red one.
     *
     * @param  array{level: string, sentence: string, more: list<string>}|null  $verdict
     */
    private function firstBackup(?array $verdict, CarbonImmutable $now): ?string
    {
        $command = 'Or press Back up now to make one within a minute.';

        return match ($verdict['level'] ?? null) {
            'red' => null,
            'amber' => $command,
            default => 'Nothing is backed up yet. The first backup starts '.$this->words->next($now).". {$command}",
        };
    }

    /**
     * The names of the places in use whose IDs are given, in the places' order.
     *
     * @param  list<string>  $ids
     *
     * @return list<string>
     */
    public function placeNames(array $ids): array
    {
        return $this->setup->placesInUse()
            ->filter(fn (BackupDestination $place): bool => in_array($place->id, $ids, true))
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * The props of tools/backups/place: the form for a new place (null), or
     * for the place in use with this ID, never a credential; an SFTP place
     * carries its public key. `place` is null when no such place is in use.
     *
     * @return array{mode: string, place: array<string, mixed>|null, suggestedFolder: string}
     */
    public function placeForm(?string $placeId): array
    {
        $form = ['mode' => $placeId === null ? 'add' : 'change', 'place' => null, 'suggestedFolder' => BackupSetup::suggestedFolder()];
        $place = $placeId === null ? null : $this->setup->placesInUse()->firstWhere('id', $placeId);

        if ($place === null) {
            return $form;
        }

        $location = $place->location;
        $sftp = $place->kind === BackupDestinationKind::Sftp;
        $private = $sftp ? ($place->credentials['private_key'] ?? null) : null;

        $form['place'] = [
            'id' => $place->id,
            'name' => $place->name,
            'kind' => $place->kind->value,
            'path' => $place->kind === BackupDestinationKind::Folder ? ($location['path'] ?? null) : null,
            'endpoint' => $location['endpoint'] ?? null,
            'bucket' => $location['bucket'] ?? null,
            'folder' => $place->kind === BackupDestinationKind::Folder ? null : ($location['path'] ?? null),
            'region' => $location['region'] ?? null,
            'host' => $location['host'] ?? null,
            'port' => isset($location['port']) ? (int) $location['port'] : null,
            'user' => $location['user'] ?? null,
            'publicKey' => is_string($private) ? SftpKeyPair::fromPrivateKey($private)->publicKey() : null,
        ];

        return $form;
    }

    /**
     * Returns the backups each place in use still keeps, newest first, at
     * most MOST_LISTED: each with its run ID (what teme:backup:restore
     * takes), the place's ID and name, when it was made, what it holds and
     * whether the check after it passed (`passed`, `failed`, or null before
     * one). Read from Teme's own record of each backup and copy, and kept
     * by the same rule the prune keeps them (I-361), so a place changed
     * outside Teme can hold otherwise; teme:backup:list asks the place.
     *
     * @since next
     *
     * @param  Collection<int, BackupDestination>  $places
     *
     * @return list<array{runId: string, placeId: string, place: string, madeAt: string, databaseBytes: int, mediaBytes: int, mediaFiles: int, checked: string|null}>
     */
    public function kept(Collection $places): array
    {
        if ($places->isEmpty()) {
            return [];
        }

        // A copy's run ID is its backup's, as the place tags it, and each place's check sits under the backup; backup_runs_destination_index serves the filter.
        $rows = DB::select(<<<'SQL'
            SELECT run.destination_id AS place, COALESCE(run.parent_id, run.id) AS run_id,
                   COALESCE(parent.started_at, run.started_at) AS made_at, COALESCE(parent.figures, run.figures) AS figures,
                   (SELECT checked.state FROM backup_runs AS checked
                     WHERE checked.parent_id = COALESCE(run.parent_id, run.id) AND checked.destination_id = run.destination_id
                       AND checked.kind = 'check' AND checked.state <> 'running'
                     ORDER BY checked.started_at DESC LIMIT 1) AS checked
            FROM backup_runs AS run
            LEFT JOIN backup_runs AS parent ON parent.id = run.parent_id
            WHERE run.destination_id = ANY (?::char(26)[])
              AND run.state = 'finished'
              AND run.kind IN ('backup', 'copy')
            ORDER BY made_at DESC, run_id DESC
            SQL, ['{'.implode(',', $places->pluck('id')->all()).'}']);

        $byPlace = [];

        foreach ($rows as $row) {
            $byPlace[(string) $row->place][] = $row;
        }

        $names = $places->pluck('name', 'id')->all();
        $whole = array_fill_keys(BackupSnapshots::PARTS, '');
        $zone = $this->settings->string('general', 'timezone');
        $listed = [];

        foreach ($byPlace as $placeId => $made) {
            $runs = array_map(fn (stdClass $row): SnapshotRun => new SnapshotRun((string) $row->run_id, CarbonImmutable::parse((string) $row->made_at), $whole, []), $made);
            $kept = array_flip(KeptRuns::choose(
                $runs,
                $this->settings->int(BackupSettings::GROUP, 'keep_daily'),
                $this->settings->int(BackupSettings::GROUP, 'keep_weekly'),
                $this->settings->int(BackupSettings::GROUP, 'keep_monthly'),
                $zone,
            ));

            foreach ($made as $row) {
                if (! isset($kept[(string) $row->run_id])) {
                    continue;
                }

                $figures = json_decode((string) $row->figures, true);
                $figures = is_array($figures) ? $figures : [];

                $listed[] = [
                    'runId' => (string) $row->run_id,
                    'placeId' => $placeId,
                    'place' => (string) ($names[$placeId] ?? ''),
                    'madeAt' => CarbonImmutable::parse((string) $row->made_at)->utc()->toIso8601String(),
                    'databaseBytes' => (int) ($figures['database_bytes'] ?? 0),
                    'mediaBytes' => (int) ($figures['media_bytes'] ?? 0),
                    'mediaFiles' => (int) ($figures['media_files'] ?? 0),
                    'checked' => $row->checked === null ? null : ($row->checked === BackupRunState::Finished->value ? 'passed' : 'failed'),
                ];
            }
        }

        usort($listed, fn (array $a, array $b): int => [$b['madeAt'], $b['runId'], $a['place']] <=> [$a['madeAt'], $a['runId'], $b['place']]);

        return array_slice($listed, 0, self::MOST_LISTED);
    }

    /**
     * @return array{when: string, databaseBytes: int, mediaFiles: int, mediaBytes: int, bytesAdded: int, took: string}
     */
    private function newestFigures(BackupRun $run, CarbonImmutable $now): array
    {
        $figures = $run->figures;

        return [
            'when' => $this->words->when($run->started_at, $now),
            'databaseBytes' => (int) ($figures['database_bytes'] ?? 0),
            'mediaFiles' => (int) ($figures['media_files'] ?? 0),
            'mediaBytes' => (int) ($figures['media_bytes'] ?? 0),
            'bytesAdded' => (int) ($figures['bytes_added'] ?? 0),
            'took' => BackupWords::duration((int) ($figures['seconds'] ?? 0)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schedule(CarbonImmutable $now): array
    {
        $daily = $this->settings->int(BackupSettings::GROUP, 'keep_daily');
        $weekly = $this->settings->int(BackupSettings::GROUP, 'keep_weekly');
        $monthly = $this->settings->int(BackupSettings::GROUP, 'keep_monthly');

        return [
            'hour' => $this->settings->string(BackupSettings::GROUP, 'hour'),
            'hours' => array_map('strval', array_keys(BackupSettings::group()->setting('hour')->options)),
            'keepDaily' => $daily,
            'keepWeekly' => $weekly,
            'keepMonthly' => $monthly,
            'next' => 'Next backup '.$this->words->next($now).'.',
            'nextAt' => $this->words->next($now),
            'kept' => BackupWords::kept($daily, $weekly, $monthly),
            'zone' => $this->settings->string('general', 'timezone'),
            'version' => (int) (SettingsGroupRecord::query()->whereKey(BackupSettings::GROUP)->value('version') ?? 0),
        ];
    }

    /**
     * @param  array<string, CarbonImmutable>  $newestThere
     * @param  list<string>  $missingKeys
     *
     * @return array<string, mixed>
     */
    private function placeRow(BackupDestination $place, CarbonImmutable $now, array $newestThere, array $missingKeys): array
    {
        $failing = $place->state === BackupDestinationState::Failing;
        $paused = $place->paused_at !== null;

        return [
            'id' => $place->id,
            'name' => $place->name,
            'kind' => $place->kind->value,
            'kindLabel' => match ($place->kind) {
                BackupDestinationKind::Folder => 'A folder on this server',
                BackupDestinationKind::S3 => 'S3-compatible storage',
                BackupDestinationKind::Sftp => 'An SFTP server',
            },
            'where' => $this->where($place),
            'offServer' => $place->kind->isOffServer(),
            'state' => $paused ? 'paused' : ($failing ? 'failing' : 'ready'),
            'stateLabel' => $paused ? 'Paused since the restore' : ($failing ? 'Failing' : 'Ready'),
            'error' => $failing && ! $paused ? $place->last_error : null,
            'newest' => isset($newestThere[$place->id]) ? $this->words->when($newestThere[$place->id], $now) : null,
            'lastAnswered' => $place->last_answered_at === null ? null : $this->words->when($place->last_answered_at, $now),
            'missingKeys' => $missingKeys,
        ];
    }

    /**
     * A place's address, without a credential.
     */
    private function where(BackupDestination $place): string
    {
        $location = $place->location;
        $path = (string) ($location['path'] ?? '');

        return match ($place->kind) {
            BackupDestinationKind::Folder => $path,
            BackupDestinationKind::S3 => $location['endpoint'].'/'.$location['bucket'].($path === '' ? '' : '/'.$path),
            BackupDestinationKind::Sftp => $location['user'].'@'.$location['host'].':'.($location['port'] ?? 22).$path,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRow(BackupRun $run, CarbonImmutable $now): array
    {
        $kind = $run->kind->value;
        $passed = $run->state === BackupRunState::Finished;
        $place = $run->destination_id === null ? null : BackupDestination::query()->whereKey($run->destination_id)->value('name');

        return [
            'id' => $run->id,
            'kind' => $kind,
            'kindLabel' => self::KIND_LABELS[$kind],
            'place' => $place,
            'passed' => $passed,
            'sentence' => $run->message ?? self::PASSED_SENTENCES[$kind],
            'when' => $this->words->when($run->started_at, $now),
        ];
    }
}
