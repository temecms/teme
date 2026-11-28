<?php

/**
 * Queues each enabled plugin's due schedules (I-3445): one pass over enabled
 * plugins that were granted jobs, whose code is present. Records each run
 * time on the plugin row in the same transaction as StartJob. Each schedule
 * runs as the administrator who enabled the plugin (`consented_by`), with
 * that person's normal permissions — never the operator's elevation. A
 * plugin whose schedules can't run, by ScheduleRunner, is skipped. A
 * schedule is due its interval after its last run was queued, not on the
 * clock, and a missed pass catches up once (docs/guides/developers/plugins/jobs.md).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Jobs;

use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\JobActor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Teme\Contracts\Plugins\Capability;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

/**
 * A schedule may name its plugin's own settings: a boolean that switches it
 * on and off, and a select that says how often it runs. They are read on
 * every pass, so a change counts at the next minute's check; a setting that
 * can't be read leaves the schedule off, or at its declared interval.
 *
 * Safe mode is not checked here: the command name matches SafeMode's
 * `teme:plugins:` prefix, so active() would always skip every schedule
 * (B-03 draft). RunPluginJob still cancels under safe mode.
 */
final readonly class DueSchedules
{
    /** @var array<string, int> */
    private const array SECONDS = [
        'minute' => 60,
        'five-minutes' => 300,
        'fifteen-minutes' => 900,
        'hour' => 3600,
        'day' => 86_400,
        'week' => 604_800,
    ];

    public function __construct(
        private PluginRegistry $registry,
        private StartJob $start,
        private ScheduleRunner $runner,
        private SettingsReader $settings,
    ) {}

    /**
     * Queues every due schedule, or only those of the plugin with this code.
     * Returns how many jobs were queued.
     */
    public function run(?CarbonImmutable $now = null, ?string $plugin = null): int
    {
        $now ??= CarbonImmutable::now();
        $queued = 0;
        $enabled = PluginRecord::query()->where('state', PluginRecord::ENABLED);

        if ($plugin !== null) {
            $enabled->where('code', $plugin);
        }

        foreach ($enabled->cursor() as $record) {
            try {
                $queued += $this->plugin($record, $now);
            } catch (Throwable $failure) {
                report($failure);
            }
        }

        return $queued;
    }

    private function plugin(PluginRecord $record, CarbonImmutable $now): int
    {
        $capabilities = $record->consent['capabilities'] ?? [];

        if (! is_array($capabilities) || ! in_array(Capability::Jobs->value, $capabilities, true)) {
            return 0;
        }

        $found = $this->registry->find($record->name);

        if (! $found instanceof FoundPlugin || ! $found->manifest instanceof Manifest) {
            return 0;
        }

        // Whom it would run as is gone or no longer allowed: skipped, and said in the log once a day (docs/proposals/plugin-sockets-1.md).
        $problem = $this->runner->problem($record);

        if ($problem !== null || $record->consented_by === null) {
            if ($problem !== null && Cache::add("teme.plugins.schedules-problem.{$record->code}.".$now->toDateString(), true, 86_400 * 2)) {
                Log::warning("{$record->name}: {$problem}");
            }

            return 0;
        }

        $declared = DeclaredJobs::of($found->manifest);
        $jobs = new PluginJobs(
            $record->name,
            $record->code,
            $this->start,
            $this->registry,
            JobActor::person($record->consented_by),
            notifyOnFinish: false,
        );
        $runs = $record->schedule_runs;
        $queued = 0;

        foreach ($declared->schedules() as $schedule) {
            if (! $this->switchedOn($record->code, $schedule['enabledBy'] ?? null)
                || ! $this->due($runs[$schedule['job']] ?? null, $this->every($record->code, $schedule['every'], $schedule['everyFrom'] ?? null), $now)) {
                continue;
            }

            DB::transaction(function () use ($jobs, $schedule, $record, $now, &$runs): void {
                $jobs->queue($schedule['job']);
                $runs[$schedule['job']] = $now->toIso8601String();
                $record->schedule_runs = $runs;
                $record->save();
            });

            $queued++;
        }

        return $queued;
    }

    /**
     * Returns when this plugin's schedule for the job is next due: its
     * interval after the last run was queued, or the time the plugin was
     * enabled when none has been, so due at the next pass. Null when the
     * plugin declares no schedule for that job, or its own setting switches it off.
     */
    public function nextDue(PluginRecord $record, string $job): ?CarbonImmutable
    {
        $found = $this->registry->find($record->name);

        if (! $found instanceof FoundPlugin || ! $found->manifest instanceof Manifest) {
            return null;
        }

        foreach (DeclaredJobs::of($found->manifest)->schedules() as $schedule) {
            $seconds = self::SECONDS[$this->every($record->code, $schedule['every'], $schedule['everyFrom'] ?? null)] ?? null;

            if ($schedule['job'] !== $job || $seconds === null) {
                continue;
            }

            // A schedule its own setting switches off is never due.
            if (! $this->switchedOn($record->code, $schedule['enabledBy'] ?? null)) {
                return null;
            }

            $last = $record->schedule_runs[$job] ?? null;

            if (! is_string($last) || $last === '') {
                return $record->enabled_at ?? CarbonImmutable::now();
            }

            return CarbonImmutable::parse($last)->addSeconds($seconds);
        }

        return null;
    }

    /**
     * Why a schedule of this plugin has no next-due time, in plain words, or null when it has one: its
     * own setting switches it off, or its interval is none Teme knows.
     *
     * @param  array{job: string, every: string, enabledBy?: string|null, everyFrom?: string|null}  $schedule
     */
    public function whyNotDue(PluginRecord $record, array $schedule): ?string
    {
        if (! $this->switchedOn($record->code, $schedule['enabledBy'] ?? null)) {
            return __('Its setting switches this schedule off.');
        }

        if (! isset(self::SECONDS[$this->every($record->code, $schedule['every'], $schedule['everyFrom'] ?? null)])) {
            return __('Its interval isn’t one Teme knows, so it never runs.');
        }

        return null;
    }

    /**
     * Whether the plugin's switch for a schedule is on: always, when the
     * schedule names none.
     */
    private function switchedOn(string $code, ?string $enabledBy): bool
    {
        if ($enabledBy === null) {
            return true;
        }

        try {
            return $this->settings->get("plugin.{$code}", $enabledBy) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * How often a schedule runs: its setting's interval when it names one
     * that holds an interval, else the one it declares.
     */
    private function every(string $code, string $declared, ?string $everyFrom): string
    {
        if ($everyFrom === null) {
            return $declared;
        }

        try {
            $chosen = $this->settings->get("plugin.{$code}", $everyFrom);
        } catch (Throwable) {
            return $declared;
        }

        return is_string($chosen) && isset(self::SECONDS[$chosen]) ? $chosen : $declared;
    }

    private function due(mixed $last, string $every, CarbonImmutable $now): bool
    {
        $seconds = self::SECONDS[$every] ?? null;

        if ($seconds === null) {
            return false;
        }

        if (! is_string($last) || $last === '') {
            return true;
        }

        return CarbonImmutable::parse($last)->addSeconds($seconds)->lessThanOrEqualTo($now);
    }
}
