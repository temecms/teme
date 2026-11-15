<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Jobs;

use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\JobActor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Plugins\Capability;

/**
 * Queues each enabled plugin's due schedules (I-3445): one pass over enabled
 * plugins that were granted jobs, whose code is present. Records each run
 * time on the plugin row in the same transaction as StartJob. Each schedule
 * runs as the administrator who enabled the plugin (`consented_by`), with
 * that person's normal permissions — never the operator's elevation.
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
    ) {}

    /**
     * Queues every due schedule. Returns how many jobs were queued.
     */
    public function run(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $queued = 0;

        foreach (PluginRecord::query()->where('state', PluginRecord::ENABLED)->cursor() as $record) {
            $queued += $this->plugin($record, $now);
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

        if ($record->consented_by === null) {
            return 0;
        }

        $declared = DeclaredJobs::of($found->manifest);
        $jobs = new PluginJobs(
            $record->name,
            $record->code,
            $this->start,
            $this->registry,
            JobActor::person($record->consented_by),
        );
        $runs = $record->schedule_runs;
        $queued = 0;

        foreach ($declared->schedules() as $schedule) {
            if (! $this->due($runs[$schedule['job']] ?? null, $schedule['every'], $now)) {
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
