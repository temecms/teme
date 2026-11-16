<?php

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

use App\Domain\Updates\GitHubReleases;
use App\Domain\Updates\ImageName;
use App\Domain\Updates\Operations\BeginTemeUpdate;
use App\Domain\Updates\Operations\EndTemeUpdate;
use App\Domain\Updates\Operations\Input\BeginTemeUpdateInput;
use App\Domain\Updates\Operations\Input\EndTemeUpdateInput;
use App\Domain\Updates\Operations\Input\RecordTemeUpdateStepInput;
use App\Domain\Updates\Operations\RecordTemeUpdateStep;
use App\Domain\Updates\Queries\SiteQuiet;
use App\Domain\Updates\Queries\TemeUpdates;
use App\Domain\Updates\Release;
use App\Domain\Updates\ReleaseCheck;
use App\Domain\Updates\Requests\TemeUpdate;
use App\Domain\Updates\Requests\UpdateStatus;
use App\Domain\Updates\Requests\UpdateStep;
use App\Domain\Updates\TemeVersion;
use App\Domain\Updates\UpdatePlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Carries out the open update one pass at a time (I-3326): each pass reads the request's row, moves one step at most
 * one state, and records it, so a restarted updater carries on from the row. It moves the service through the
 * platform with one deploy, watches each part, and switches back by deploying the old image when the new release
 * isn't healthy.
 */
final readonly class UpdateRunner
{
    public const int PROBES = 3;

    /** Seconds a step waits for the platform to list a deployment before asking again. */
    public const int LIST_GRACE_SECONDS = 30;

    /** What the deploy step says while it waits for a backup or a job to finish. */
    private const string WAITING = 'Waiting for a backup or a job to finish first.';

    /** What the deploy step says when it asked without waiting any longer. */
    private const string ASKED_ANYWAY = 'Asked while a backup or a job was still running; the site may be down longer.';

    public function __construct(
        private TemeUpdates $updates,
        private Platform $platform,
        private VersionProbe $probe,
        private GitHubReleases $github,
        private UpdatePlan $plan,
        private BeginTemeUpdate $begin,
        private RecordTemeUpdateStep $record,
        private EndTemeUpdate $end,
        private SiteQuiet $quiet,
    ) {}

    /**
     * Takes one pass over the open request, if there is one.
     */
    public function tick(): void
    {
        $update = $this->updates->open();

        if ($update === null) {
            return;
        }

        try {
            match (true) {
                $update->status === UpdateStatus::Requested => $this->beginOrRefuse($update),
                $update->switchingBack() => $this->switchBack($update),
                default => $this->advance($update),
            };
        } catch (PlatformUnreadable $unreadable) {
            $this->unreadable($update->id, $unreadable->getMessage());
        } catch (PlatformRefused $refused) {
            $this->refused($update->id, $refused->getMessage());
        }
    }

    /**
     * Whether an update is under way, which is how often the updater looks.
     */
    public function running(): bool
    {
        return $this->updates->open()?->status === UpdateStatus::Running;
    }

    private function beginOrRefuse(TemeUpdate $update): void
    {
        if (TemeVersion::current() !== $update->from) {
            $this->refuse($update, $this->stale($update) ? 'The updater runs Teme '.TemeVersion::current().", but the site runs {$update->from}. Set TEME_UPDATER_IMAGE to Teme {$update->from}’s image on the platform and deploy, then request the update again." : 'This site runs Teme '.TemeVersion::current()." now, not {$update->from}. Request the update again.");

            return;
        }

        // Built from the row's versions each time they are needed; nothing stores an image.
        $old = ImageName::for($update->from);
        ImageName::for($update->to);

        $refusal = $this->plan->refusal($update->kind, $update->to, ignoring: $update->id);

        if ($refusal !== null) {
            $this->refuse($update, $refusal);

            return;
        }

        $fresh = $this->github->ask(null);

        if ($fresh->outcome !== ReleaseCheck::FOUND) {
            $this->refuse($update, 'Teme couldn’t read the release list from GitHub just now, so nothing was changed: '.(string) $fresh->error);

            return;
        }

        $listed = array_filter($fresh->releases, fn (Release $release): bool => $release->version === $update->to);

        if ($listed === []) {
            $this->refuse($update, "GitHub no longer lists Teme {$update->to}, so nothing was changed.");

            return;
        }

        [$older, $newer] = TemeVersion::compare($update->from, $update->to) < 0 ? [$update->from, $update->to] : [$update->to, $update->from];
        $marked = UpdatePlan::marksBetween($older, $newer, $fresh->releases);

        if ($marked !== null) {
            $this->refuse($update, $marked);

            return;
        }

        foreach (ImageSetting::cases() as $setting) {
            // Compared, never echoed: the platform's answer is not Teme's to repeat.
            if ($this->platform->image($setting) !== $old) {
                $this->refuse($update, "{$setting->value} isn’t set to Teme {$update->from}’s image on the platform, so nothing was changed. Set it to {$update->from}’s image first.");

                return;
            }
        }

        if (! $this->begin->execute(new BeginTemeUpdateInput($update->id))) {
            return;
        }

        $begun = $this->updates->find($update->id);

        if ($begun !== null) {
            $this->advance($begun);
        }
    }

    /**
     * Whether the site moved to the request's from version and the updater didn't: the platform says so, never the row.
     */
    private function stale(TemeUpdate $update): bool
    {
        try {
            return $this->platform->image(ImageSetting::Site) === ImageName::for($update->from);
        } catch (PlatformRefused) {
            return false;
        }
    }

    private function advance(TemeUpdate $update): void
    {
        $step = $update->currentStep();

        match ($step) {
            null => $this->end($update, UpdateStatus::Done, null),
            UpdateStep::Deploy => $this->advanceDeploy($update),
            UpdateStep::Web => $this->advanceWeb($update),
            UpdateStep::Check => $this->advanceCheck($update),
            UpdateStep::Updater => $this->advanceUpdater($update),
            default => $this->advanceHealth($update, $step),
        };
    }

    /**
     * The one step that moves the site: asks for the deploy once nothing is in flight, then waits for the deploy's own record.
     */
    private function advanceDeploy(TemeUpdate $update): void
    {
        $entry = (array) $update->entry(UpdateStep::Deploy);
        $state = $update->state(UpdateStep::Deploy);

        if ($state === UpdateStep::PENDING) {
            $this->askForDeploy($update, $entry);

            return;
        }

        if ($state === UpdateStep::FAILED) {
            // Recorded failed, but the switch back was never marked: mark it now.
            $this->record($update, UpdateStep::Check, UpdateStep::FAILED, (string) ($entry['detail'] ?? ''));

            return;
        }

        $run = DB::table('deploy_runs')
            ->where('version', $update->to)
            ->when(isset($entry['runsAfter']), fn ($query) => $query->where('finished_at', '>', $entry['runsAfter']))
            ->orderByDesc('finished_at')
            ->first();

        if ($run !== null) {
            $backup = $run->backup_run_id === null ? null : (string) $run->backup_run_id;

            if ((int) $run->exit_code === 0) {
                $this->record($update, UpdateStep::Deploy, UpdateStep::DONE, null, $backup, (string) $run->id);

                return;
            }

            $this->failDeploy($update, (string) $run->sentence, $backup, (string) $run->id);

            return;
        }

        // Never switched back while the deploy may still be migrating: it waits for its record, or the hour.
        if ($this->age($entry) > (int) config('teme.updater.deploy_timeout')) {
            $this->failDeploy($update, 'The deploy didn’t record its end within an hour. See the service’s deployment log on the platform.');

            return;
        }

        $deployment = $this->counted($entry);

        if ($deployment?->state === PlatformDeployment::ERROR && $this->age($entry) >= self::LIST_GRACE_SECONDS) {
            $this->failDeploy($update, "The platform’s deployment of Teme {$update->to} ended in {$deployment->state} before the deploy ran. See the service’s deployment log on the platform.");

            return;
        }

        if ($deployment === null && $this->mayAskAgain($entry)) {
            // A crash after the record, a job the platform lost, or one still queued: ask once more, and only once.
            $this->askAgain($update, UpdateStep::Deploy, $entry);
        }
    }

    /**
     * Asks for the deploy when no other deployment of the service runs and nothing is in flight, or says why it waits.
     *
     * @param  array<string, mixed>  $entry
     */
    private function askForDeploy(TemeUpdate $update, array $entry): void
    {
        $latest = $this->latest();
        $waited = $this->since($update->startedAt) > (int) config('teme.updater.part_timeout');

        if ($latest?->state === PlatformDeployment::RUNNING) {
            if ($waited) {
                $this->end($update, UpdateStatus::Failed, 'Another deployment of the Compose service was still running after 15 minutes, so nothing was changed.');
            }

            return;
        }

        $detail = null;

        if (! $this->quiet->isQuiet()) {
            if (! $waited) {
                if (($entry['detail'] ?? null) !== self::WAITING) {
                    $this->record($update, UpdateStep::Deploy, UpdateStep::PENDING, self::WAITING);
                }

                return;
            }

            $detail = self::ASKED_ANYWAY;
        }

        $image = ImageName::for($update->to);
        $mark = DB::table('deploy_runs')->max('finished_at');
        $this->record($update, UpdateStep::Deploy, UpdateStep::STARTED, $detail, runsAfter: $mark === null ? null : CarbonImmutable::parse((string) $mark)->format('Y-m-d\TH:i:s.uP'), mark: $latest?->id);

        try {
            $this->platform->setImage(ImageSetting::Site, $image);
        } catch (PlatformRefused $refused) {
            // Nothing was deployed, so the containers are the old ones: the setting goes back, and the update ends.
            $this->abandon($update, $refused->getMessage());

            return;
        }

        $this->platform->deploy("Teme {$update->to}");
    }

    /**
     * Records the deploy step failed, and the switch back as under way.
     */
    private function failDeploy(TemeUpdate $update, string $sentence, ?string $backupRunId = null, ?string $deployRunId = null): void
    {
        $this->record($update, UpdateStep::Deploy, UpdateStep::FAILED, $sentence, $backupRunId, $deployRunId);
        $this->record($update, UpdateStep::Check, UpdateStep::FAILED, $sentence);
    }

    /**
     * Watches the deployment end, then the new release answer as the site.
     */
    private function advanceWeb(TemeUpdate $update): void
    {
        $entry = (array) $update->entry(UpdateStep::Web);
        $state = $update->state(UpdateStep::Web);

        if ($state === UpdateStep::PENDING) {
            // Nothing to ask: the deploy asked for every part. The deployment it made is the one counted.
            $this->record($update, UpdateStep::Web, UpdateStep::STARTED, mark: $this->deployMark($update));

            return;
        }

        if ($state === UpdateStep::FAILED) {
            $this->record($update, UpdateStep::Check, UpdateStep::FAILED, (string) ($entry['detail'] ?? ''));

            return;
        }

        $deployment = $this->counted($entry);
        $slow = $this->age($entry) > (int) config('teme.updater.part_timeout');

        if ($deployment === null) {
            if ($slow) {
                $this->startSwitchBack($update, UpdateStep::Web, 'web didn’t finish deploying within 15 minutes.');
            } elseif ($this->mayAskAgain($entry)) {
                $this->askAgain($update, UpdateStep::Web, $entry);
            }

            return;
        }

        if ($deployment->state === PlatformDeployment::ERROR) {
            $this->startSwitchBack($update, UpdateStep::Web, "web’s deployment ended in {$deployment->state} on the platform.");

            return;
        }

        if ($deployment->state !== PlatformDeployment::DONE) {
            if ($slow) {
                $this->startSwitchBack($update, UpdateStep::Web, 'web didn’t finish deploying within 15 minutes.');
            }

            return;
        }

        if ($slow) {
            $this->startSwitchBack($update, UpdateStep::Web, "web didn’t answer its health check with Teme {$update->to} within 15 minutes.");

            return;
        }

        $this->probeToward($update, UpdateStep::Web, $entry, $update->to, UpdateStep::DONE);
    }

    /**
     * Waits for one of the workers or the scheduler to run and pass its health check on the new release.
     */
    private function advanceHealth(TemeUpdate $update, UpdateStep $step): void
    {
        $entry = (array) $update->entry($step);
        $state = $update->state($step);

        if ($state === UpdateStep::PENDING) {
            $this->record($update, $step, UpdateStep::STARTED, mark: $this->deployMark($update));

            return;
        }

        if ($state === UpdateStep::FAILED) {
            $this->record($update, UpdateStep::Check, UpdateStep::FAILED, (string) ($entry['detail'] ?? ''));

            return;
        }

        $health = $this->health()[$step->value] ?? PartHealth::Missing;

        if ($health === PartHealth::Healthy) {
            $this->record($update, $step, UpdateStep::DONE);
        } elseif ($this->age($entry) > (int) config('teme.updater.part_timeout')) {
            $this->startSwitchBack($update, $step, "{$step->value} wasn’t healthy on Teme {$update->to} within 15 minutes.");
        }
    }

    /**
     * Checks once more, over every part and the site, that the new release is what runs.
     */
    private function advanceCheck(TemeUpdate $update): void
    {
        $entry = (array) $update->entry(UpdateStep::Check);

        if ($update->state(UpdateStep::Check) === UpdateStep::PENDING) {
            $this->record($update, UpdateStep::Check, UpdateStep::STARTED, mark: $this->deployMark($update));

            return;
        }

        $unhealthy = "Not every part was running Teme {$update->to} at the last check.";
        $deployment = $this->counted($entry);

        if ($deployment?->state === PlatformDeployment::ERROR) {
            $this->startSwitchBack($update, UpdateStep::Check, $unhealthy);

            return;
        }

        $healthy = $deployment?->state === PlatformDeployment::DONE
            && count(array_filter($this->health(), fn (PartHealth $health): bool => $health !== PartHealth::Healthy)) === 0;

        if (! $healthy) {
            if ($this->age($entry) > (int) config('teme.updater.part_timeout')) {
                $this->startSwitchBack($update, UpdateStep::Check, $unhealthy);
            }

            return;
        }

        $this->probeToward($update, UpdateStep::Check, $entry, $update->to, UpdateStep::DONE);
    }

    /**
     * Asks, fire and forget, for the updater to restart on the new release, then ends the update: nothing waits
     * on the updater replacing itself, so a self-deploy never leaves a request open.
     */
    private function advanceUpdater(TemeUpdate $update): void
    {
        $asked = "asked to start on {$update->to}";

        // In one Application the updater is in the container the new release replaced: it is already on it.
        if (! $this->platform->hasUpdaterOfItsOwn()) {
            $this->record($update, UpdateStep::Updater, UpdateStep::DONE, 'runs in the site’s container');
            $this->end($update, UpdateStatus::Done, null);

            return;
        }

        // A new updater that started before the old one ended the row: nothing is left to ask.
        if (TemeVersion::current() === $update->to) {
            $this->record($update, UpdateStep::Updater, UpdateStep::DONE, $asked);
            $this->end($update, UpdateStatus::Done, null);

            return;
        }

        $state = $update->state(UpdateStep::Updater);
        $entry = (array) $update->entry(UpdateStep::Updater);

        if ($state === UpdateStep::FAILED) {
            // Recorded "not restarted", then a crash before the end: the update itself is done.
            $this->end($update, UpdateStatus::Done, null);

            return;
        }

        try {
            if ($state === UpdateStep::PENDING) {
                $image = ImageName::for($update->to);
                $this->recordAsking($update, UpdateStep::Updater);
                $this->platform->setImage(ImageSetting::Updater, $image);
                $this->platform->deploy("Teme {$update->to}, the updater");
            } elseif ($this->counted($entry) === null) {
                if ($this->age($entry) < self::LIST_GRACE_SECONDS) {
                    return;
                }

                if ($this->mayAskAgain($entry)) {
                    $this->record($update, UpdateStep::Updater, UpdateStep::STARTED, $entry['detail'] ?? null, asks: 2);
                    $this->platform->setImage(ImageSetting::Updater, ImageName::for($update->to));
                    $this->platform->deploy("Teme {$update->to}, the updater");
                }
            }
        } catch (PlatformRefused|PlatformUnreadable) {
            // The update is done: the site runs the new release. Only the updater didn't move, and it says so.
            $this->record($update, UpdateStep::Updater, UpdateStep::FAILED, "The updater couldn’t be asked to restart on Teme {$update->to}, so it still runs {$update->from}. Set TEME_UPDATER_IMAGE to Teme {$update->to}’s image on the platform and deploy.");
            $this->end($update, UpdateStatus::Done, null);

            return;
        }

        $this->record($update, UpdateStep::Updater, UpdateStep::DONE, $asked);
        $this->end($update, UpdateStatus::Done, null);
    }

    /**
     * Counts one more answer from the site with $version, or starts the count again, and records the step
     * as $then once it has answered PROBES times in a row.
     *
     * @param  array<string, mixed>  $entry
     */
    private function probeToward(TemeUpdate $update, UpdateStep $step, array $entry, string $version, string $then): void
    {
        $before = self::answers($entry['detail'] ?? null);
        $now = $this->probe->version() === $version ? $before + 1 : 0;

        if ($now >= self::PROBES) {
            $this->record($update, $step, $then);
        } elseif ($now !== $before) {
            $this->record($update, $step, UpdateStep::STARTED, $now === 0 ? null : "{$now} of ".self::PROBES." answers with Teme {$version}");
        }
    }

    private static function answers(mixed $detail): int
    {
        return is_string($detail) && preg_match('/^(\d+) of \d+ answers with Teme /', $detail, $found) === 1 ? (int) $found[1] : 0;
    }

    /**
     * Marks the switch back as under way, and what made it needed.
     */
    private function startSwitchBack(TemeUpdate $update, UpdateStep $step, string $reason): void
    {
        // The step in hand first, so a crash between the two records is found and finished.
        if ($step !== UpdateStep::Check && $update->state($step) === UpdateStep::STARTED) {
            $this->record($update, $step, UpdateStep::FAILED, $reason);
        }

        $this->record($update, UpdateStep::Check, UpdateStep::FAILED, $reason);
    }

    /**
     * Puts the service back on the old release with one deploy of the old image, then waits for the site to
     * answer as it. The deploy step's record holds its progress: started while it is asked and watched,
     * switched back once the old release answers.
     */
    private function switchBack(TemeUpdate $update): void
    {
        $state = $update->state(UpdateStep::Deploy);
        $entry = (array) $update->entry(UpdateStep::Deploy);

        if ($state === UpdateStep::SWITCHED_BACK) {
            $this->finishSwitchBack($update);

            return;
        }

        if ($state === UpdateStep::STARTED) {
            $this->settleSwitchBack($update, $entry);

            return;
        }

        $latest = $this->latest();

        // The forward deployment may still run: two deploys of one project never run at once.
        if ($latest?->state === PlatformDeployment::RUNNING && $this->since($this->switchBackStartedAt($update)) <= (int) config('teme.updater.part_timeout')) {
            return;
        }

        $this->record($update, UpdateStep::Deploy, UpdateStep::STARTED, mark: $latest?->id);
        $this->platform->setImage(ImageSetting::Site, ImageName::for($update->from));
        $this->platform->deploy("Teme {$update->from}, switched back");
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function settleSwitchBack(TemeUpdate $update, array $entry): void
    {
        $deployment = $this->counted($entry);
        $slow = $this->age($entry) > (int) config('teme.updater.part_timeout');

        if ($deployment === null) {
            if ($slow) {
                $this->switchBackFailed($update, 'the service didn’t finish deploying within 15 minutes');
            } elseif ($this->mayAskAgain($entry)) {
                $this->record($update, UpdateStep::Deploy, UpdateStep::STARTED, $entry['detail'] ?? null, asks: 2);
                $this->platform->setImage(ImageSetting::Site, ImageName::for($update->from));
                $this->platform->deploy("Teme {$update->from}, switched back");
            }

            return;
        }

        if ($deployment->state === PlatformDeployment::ERROR) {
            $this->switchBackFailed($update, "the service’s deployment ended in {$deployment->state} on the platform");

            return;
        }

        if ($deployment->state !== PlatformDeployment::DONE) {
            if ($slow) {
                $this->switchBackFailed($update, 'the service didn’t finish deploying within 15 minutes');
            }

            return;
        }

        if ($slow) {
            $this->switchBackFailed($update, "web didn’t answer its health check with Teme {$update->from} within 15 minutes");

            return;
        }

        $before = self::answers($entry['detail'] ?? null);
        $now = $this->probe->version() === $update->from ? $before + 1 : 0;

        if ($now >= self::PROBES) {
            $this->record($update, UpdateStep::Deploy, UpdateStep::SWITCHED_BACK);
            $this->finishSwitchBack($this->updates->find($update->id) ?? $update);
        } elseif ($now !== $before) {
            $this->record($update, UpdateStep::Deploy, UpdateStep::STARTED, $now === 0 ? null : "{$now} of ".self::PROBES." answers with Teme {$update->from}");
        }
    }

    /**
     * Records every step that had started as switched back, and ends the request: failed when the release never
     * got past the deploy (the backup is named on the row), switched back otherwise.
     */
    private function finishSwitchBack(TemeUpdate $update): void
    {
        $reason = (string) ($update->entry(UpdateStep::Check)['detail'] ?? '');
        $neverMoved = $update->state(UpdateStep::Web) === UpdateStep::PENDING;

        foreach (UpdateStep::cases() as $step) {
            if (! in_array($update->state($step), [UpdateStep::PENDING, UpdateStep::SWITCHED_BACK], true)) {
                $this->record($update, $step, UpdateStep::SWITCHED_BACK, $step === UpdateStep::Check ? $reason : null);
            }
        }

        $this->end($update, $neverMoved ? UpdateStatus::Failed : UpdateStatus::SwitchedBack, $reason);
    }

    private function switchBackFailed(TemeUpdate $update, string $reason): void
    {
        $this->end($update, UpdateStatus::Failed, "Switching back to {$update->from} failed too: ".rtrim($reason, '.').". Set TEME_IMAGE back to {$update->from}’s image by hand on the platform and deploy.");
    }

    /**
     * When the switch back began: the moment the last check was recorded failed.
     */
    private function switchBackStartedAt(TemeUpdate $update): ?CarbonImmutable
    {
        $finished = $update->entry(UpdateStep::Check)['finishedAt'] ?? null;

        return $finished === null ? $update->startedAt : CarbonImmutable::parse((string) $finished);
    }

    /**
     * Ends the request for a refusal that came from the platform or from Teme's guard in front of it.
     */
    private function refused(string $id, string $message): void
    {
        $update = $this->updates->find($id);

        if ($update === null || ! $update->status->isOpen()) {
            return;
        }

        match (true) {
            $update->status === UpdateStatus::Requested => $this->refuse($update, $message),
            $update->switchingBack() => $this->switchBackFailed($update, $message),
            // Nothing was asked yet: the service is as it was.
            $update->state(UpdateStep::Deploy) === UpdateStep::PENDING => $this->end($update, UpdateStatus::Failed, $message),
            // The deploy may have been queued: switch back.
            default => $this->startSwitchBack($update, $update->currentStep() ?? UpdateStep::Check, $message),
        };
    }

    /**
     * Handles a read the platform refused, which counts nothing this pass: a step never switches back on a read
     * alone, and its own time limit decides while the platform stays unreadable. The deploy step checks its hour
     * before it reads, so it waits for its own record (R8).
     */
    private function unreadable(string $id, string $message): void
    {
        $update = $this->updates->find($id);

        if ($update === null || ! $update->status->isOpen()) {
            return;
        }

        $limit = (int) config('teme.updater.part_timeout');

        if ($update->switchingBack()) {
            $waited = $update->state(UpdateStep::Deploy) === UpdateStep::STARTED
                ? $this->age((array) $update->entry(UpdateStep::Deploy))
                : $this->since($this->switchBackStartedAt($update));

            if ($waited > $limit) {
                $this->switchBackFailed($update, $message);
            }

            return;
        }

        if ($update->status === UpdateStatus::Requested || $update->state(UpdateStep::Deploy) === UpdateStep::PENDING) {
            // Nothing was asked yet: the service is as it was.
            $this->refused($id, $message);

            return;
        }

        $step = $update->currentStep();

        if ($step === null || $step === UpdateStep::Deploy || $step === UpdateStep::Updater) {
            return;
        }

        if ($this->age((array) $update->entry($step)) > $limit) {
            $this->startSwitchBack($update, $step, "Teme couldn’t check {$step->value} within 15 minutes: {$message}");
        }
    }

    /**
     * Returns the service's newest deployment, or null when it lists none; a refused read is PlatformUnreadable.
     *
     * @throws PlatformUnreadable
     */
    private function latest(): ?PlatformDeployment
    {
        try {
            return $this->platform->latestDeployment();
        } catch (PlatformRefused $refused) {
            throw PlatformUnreadable::from($refused);
        }
    }

    /**
     * Returns each part's health, keyed by the part's name; a refused read is PlatformUnreadable.
     *
     * @return array<string, PartHealth>
     *
     * @throws PlatformUnreadable
     */
    private function health(): array
    {
        try {
            return $this->platform->health();
        } catch (PlatformRefused $refused) {
            throw PlatformUnreadable::from($refused);
        }
    }

    /**
     * Ends a request the updater refused before it began; the platform's settings are untouched.
     */
    private function refuse(TemeUpdate $update, string $reason): void
    {
        $this->end($update, UpdateStatus::Failed, $reason);
    }

    /**
     * Ends a request whose first ask for the deploy was refused at the image setting: the setting goes back to the
     * old image without a deploy, since nothing was deployed, and the update ends failed.
     */
    private function abandon(TemeUpdate $update, string $reason): void
    {
        try {
            $this->platform->setImage(ImageSetting::Site, ImageName::for($update->from));
        } catch (PlatformRefused) {
            // The platform is what failed: the reason is already the one to show.
        }

        $this->end($update, UpdateStatus::Failed, $reason);
    }

    private function end(TemeUpdate $update, UpdateStatus $status, ?string $error): void
    {
        $this->end->execute(new EndTemeUpdateInput($update->id, $status, $error));
    }

    private function record(TemeUpdate $update, UpdateStep $step, string $state, ?string $detail = null, ?string $backupRunId = null, ?string $deployRunId = null, ?int $asks = null, ?string $runsAfter = null, ?string $mark = null): void
    {
        // A step that starts from another state has asked the platform once, as its record is made.
        $asks ??= $state === UpdateStep::STARTED && $update->state($step) !== UpdateStep::STARTED ? 1 : null;

        $this->record->execute(new RecordTemeUpdateStepInput($update->id, $step, $state, $detail, $backupRunId, $deployRunId, $asks, $runsAfter, $mark));
    }

    /**
     * Records the step started, with the newest deployment the platform lists now as its mark. The platform
     * is read first, so a refusal there leaves the step as it was.
     */
    private function recordAsking(TemeUpdate $update, UpdateStep $step): void
    {
        $this->record($update, $step, UpdateStep::STARTED, mark: $this->latest()?->id);
    }

    /**
     * Asks once more for the deploy of the service, for a step whose deployment never listed or was lost.
     *
     * @param  array<string, mixed>  $entry
     */
    private function askAgain(TemeUpdate $update, UpdateStep $step, array $entry): void
    {
        $this->record($update, $step, UpdateStep::STARTED, $entry['detail'] ?? null, asks: 2);
        $this->platform->setImage(ImageSetting::Site, ImageName::for($update->to));
        $this->platform->deploy("Teme {$update->to}");
    }

    /**
     * Returns the deployment the deploy step saw before it asked, which the later steps count against.
     */
    private function deployMark(TemeUpdate $update): ?string
    {
        $mark = $update->entry(UpdateStep::Deploy)['mark'] ?? null;

        return is_string($mark) ? $mark : null;
    }

    /**
     * Whether a step that has heard nothing from the platform may ask it once more: the listing grace has
     * passed and it has asked only once. Its start is kept, so every time limit still holds.
     *
     * @param  array<string, mixed>  $entry
     */
    private function mayAskAgain(array $entry): bool
    {
        return $this->age($entry) >= self::LIST_GRACE_SECONDS && (int) ($entry['asks'] ?? 1) < 2;
    }

    /**
     * Returns the service's newest deployment when the step asked for it: not the one the platform listed
     * before the step asked, and not a cancelled one, which is a job the platform lost and never a failure.
     * No clock is compared.
     *
     * @param  array<string, mixed>  $entry
     */
    private function counted(array $entry): ?PlatformDeployment
    {
        $deployment = $this->latest();

        if ($deployment === null || $deployment->id === ($entry['mark'] ?? null) || $deployment->state === PlatformDeployment::CANCELLED) {
            return null;
        }

        return $deployment;
    }

    /**
     * Returns how many seconds ago the step started.
     *
     * @param  array<string, mixed>|null  $entry
     */
    private function age(?array $entry): int
    {
        return $this->since(isset($entry['startedAt']) ? CarbonImmutable::parse((string) $entry['startedAt']) : null);
    }

    private function since(?CarbonImmutable $moment): int
    {
        return $moment === null ? 0 : max(0, (int) $moment->diffInSeconds(CarbonImmutable::now(), false));
    }
}
