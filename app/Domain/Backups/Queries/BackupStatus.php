<?php

declare(strict_types=1);

namespace App\Domain\Backups\Queries;

use App\Domain\Backups\BackupVerdict;
use App\Domain\Backups\BackupWords;
use App\Domain\Backups\Verify\SpareDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

/**
 * The site's backups in one verdict (I-2409), read from the
 * backup tables, two settings and the configuration in four statements,
 * never from restic: on every admin page's banner, in the alert email and
 * on Tools › Backups, so the three always agree. When it says when the first
 * backup starts it asks BackupWords, as the rest of that screen does.
 */
final readonly class BackupStatus
{
    /** How long after the hour a backup that hasn't started is overdue. */
    public const int GRACE_HOURS = 2;

    public const string NOT_SET_UP = 'Nothing on this site is backed up yet. Set backups up on Tools › Backups.';

    public const string OFF_SERVER = 'Every backup is on this server, which a failed disk or a lost server would take with it. Add a place off this server.';

    public const string NO_SPARE = 'Backups aren’t test-restored each week: no spare database is named. At the server, name one as TEME_VERIFY_DATABASE.';

    public const string NO_REASON = 'Teme recorded no reason; the log may say more.';

    /** The checks whose newest whole run, when it failed, turns the verdict red, in the order they're told. */
    private const array CHECKS = [
        'prune' => 'The last weekly removal of old backups failed: ',
        'read-back' => 'The last nightly read-back failed: ',
        'test-restore' => 'The last weekly test restore failed: ',
    ];

    public function __construct(private BackupPlaces $places, private DueBackupWork $schedule, private SettingsReader $settings, private BackupWords $words) {}

    /**
     * The verdict at $now (now by default): red problems, those of them
     * that email, amber cautions, and the newest good backup's end.
     */
    public function verdict(?CarbonImmutable $now = null): BackupVerdict
    {
        $now ??= CarbonImmutable::now();

        // One statement: whether and since when backups are set up, whether a place in use is off this server, and the backups' newest moments.
        $facts = DB::selectOne(<<<'SQL'
            SELECT
                (SELECT min(k.added_at) FROM backup_keys k JOIN backup_destinations d ON d.id = k.destination_id
                    WHERE k.role = 'recovery' AND k.removed_at IS NULL AND d.state <> 'removed') AS set_up_at,
                EXISTS (SELECT 1 FROM backup_destinations WHERE state IN ('ready', 'failing') AND off_server) AS off_server,
                (SELECT started_at FROM backup_runs WHERE kind = 'backup' AND parent_id IS NULL
                    ORDER BY started_at DESC LIMIT 1) AS last_started,
                (SELECT ended_at FROM backup_runs WHERE kind = 'backup' AND parent_id IS NULL AND state = 'finished'
                    ORDER BY started_at DESC LIMIT 1) AS last_good,
                EXISTS (SELECT 1 FROM backup_runs WHERE kind = 'backup' AND parent_id IS NULL AND state = 'running') AS backing_up
            SQL);

        // Set up exactly as BackupPlaces::isSetUp() says: a recovery key on a place in use (I-2401).
        if ($facts->set_up_at === null) {
            return new BackupVerdict([self::NOT_SET_UP], [], [], null);
        }

        $pause = $this->places->isPaused()
            // A choice a person makes after a restore: red, never emailed (I-2409).
            ? [BackupPlaces::PAUSED.' Answer on Tools › Backups.']
            : [];
        $zone = $this->settings->string('general', 'timezone');
        $setUpAt = CarbonImmutable::parse((string) $facts->set_up_at);
        $lastStarted = $facts->last_started === null ? null : CarbonImmutable::parse((string) $facts->last_started);
        $lastGood = $facts->last_good === null ? null : CarbonImmutable::parse((string) $facts->last_good);
        $problems = $pause;
        $alerting = [];
        $cautions = [];
        $newest = $this->newestEnded();
        $backup = $newest['backup'] ?? null;
        $backupFailed = $backup !== null && ($backup->state === 'failed' || (int) ($backup->missing ?? 0) > 0);

        if ($backupFailed) {
            $problems[] = $alerting[] = 'The last backup failed: '.(string) ($backup->message ?? self::NO_REASON);
        } elseif ($backup !== null) {
            // backup_runs_parent_index: the backup's copies and checks.
            $step = DB::selectOne("SELECT kind, message FROM backup_runs WHERE parent_id = ? AND state = 'failed' ORDER BY started_at LIMIT 1", [$backup->id]);

            if ($step !== null) {
                $problems[] = $alerting[] = ($step->kind === 'copy' ? 'The last backup didn’t reach every place: ' : 'A check of the last backup failed: ').(string) ($step->message ?? self::NO_REASON);
            }
        }

        // The hour's latest time, two hours ago: a backup should have started by then, once backups were set up.
        $since = $this->schedule->slot($now->subHours(self::GRACE_HOURS));
        $overdue = $pause === [] && $setUpAt->lessThan($since) && ($lastStarted === null || $lastStarted->lessThan($since));

        if ($overdue) {
            $problems[] = $alerting[] = 'No backup has started since '.$since->setTimezone($zone)->format('j F Y \a\t H:i').': the server’s scheduler may have stopped. It must run php artisan schedule:run every minute.';
        }

        foreach (self::CHECKS as $kind => $lead) {
            $run = $newest[$kind] ?? null;

            if ($run !== null && $run->state === 'failed') {
                $problems[] = $alerting[] = $lead.(string) ($run->message ?? self::NO_REASON);
            }
        }

        if ($lastGood === null && ! $overdue && ! $backupFailed) {
            $cautions[] = (bool) $facts->backing_up
                ? 'No backup has finished yet: the first is running now.'
                : 'No backup has finished yet. The first starts '.$this->words->next($now).'.';
        }

        // I-355.
        if (! (bool) $facts->off_server) {
            $cautions[] = self::OFF_SERVER;
        }

        // I-2406.
        if (! SpareDatabase::isNamed()) {
            $cautions[] = self::NO_SPARE;
        }

        return new BackupVerdict($problems, $alerting, $cautions, $lastGood);
    }

    /**
     * The newest ended whole run of each kind, by kind, in one statement
     * through backup_runs_kind_index: its id, state, message and missing
     * files.
     *
     * @return array<string, object>
     */
    private function newestEnded(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT k.kind, r.id, r.state, r.message, r.figures ->> 'missing_files' AS missing
            FROM unnest(?::text[]) AS k(kind)
            JOIN LATERAL (
                SELECT id, state, message, figures FROM backup_runs
                WHERE backup_runs.kind = k.kind AND parent_id IS NULL AND state <> 'running'
                ORDER BY started_at DESC
                LIMIT 1
            ) r ON true
            SQL, ['{backup,prune,read-back,test-restore}']);

        $newest = [];

        foreach ($rows as $row) {
            $newest[(string) $row->kind] = $row;
        }

        return $newest;
    }
}
