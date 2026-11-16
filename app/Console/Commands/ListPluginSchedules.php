<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\Jobs\DeclaredJobs;
use App\Domain\Plugins\Jobs\DueSchedules;
use App\Domain\Plugins\Jobs\ScheduleRunner;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginRegistry;
use Illuminate\Console\Command;

/**
 * `teme:plugins:schedules`: each enabled plugin's schedules, with how often
 * they run, when each was last queued and is next due, and why they can't
 * run, if they can't (docs/guides/developers/plugins/jobs.md).
 */
final class ListPluginSchedules extends Command
{
    /** @var string */
    protected $signature = 'teme:plugins:schedules';

    /** @var string */
    protected $description = 'List the enabled plugins’ schedules, when each is next due, and why one can’t run';

    public function handle(PluginRegistry $registry, DueSchedules $due, ScheduleRunner $runner): int
    {
        $rows = [];

        foreach (PluginRecord::query()->where('state', PluginRecord::ENABLED)->orderBy('name')->get() as $record) {
            $found = $registry->find($record->name);

            if (! $found instanceof FoundPlugin || ! $found->manifest instanceof Manifest) {
                continue;
            }

            $why = $runner->problem($record) ?? '';

            foreach (DeclaredJobs::of($found->manifest)->schedules() as $schedule) {
                $rows[] = [
                    $record->name,
                    $schedule['job'],
                    $schedule['every'],
                    $record->schedule_runs[$schedule['job']] ?? __('never'),
                    $due->nextDue($record, $schedule['job'])?->toIso8601String() ?? '',
                    $why,
                ];
            }
        }

        if ($rows === []) {
            $this->components->info(__('No enabled plugin has a schedule.'));

            return self::SUCCESS;
        }

        $this->table([__('Plugin'), __('Job'), __('Every'), __('Last queued'), __('Next due'), __('Why it can’t run')], $rows);

        return self::SUCCESS;
    }
}
