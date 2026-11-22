<?php

/**
 * `teme:plugins:schedules`: each enabled plugin's schedules, with how often
 * they run, when each was last queued and is next due, and why they can't
 * run, if they can't (docs/guides/developers/plugins/jobs.md).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Plugins\Consent;
use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\Jobs\DeclaredJobs;
use App\Domain\Plugins\Jobs\DueSchedules;
use App\Domain\Plugins\Jobs\ScheduleRunner;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginRegistry;
use Illuminate\Console\Command;

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

            // An update that asks for more than was agreed runs nothing: every run it queues is cancelled.
            $why = $runner->problem($record) ?? (Consent::covers($record->consent, $found->manifest) ? '' : __('Waiting for its new permissions to be agreed.'));

            foreach (DeclaredJobs::of($found->manifest)->schedules() as $schedule) {
                // No time for a schedule that can't run: its plugin can't, or this schedule can't.
                $own = $why === '' ? $due->whyNotDue($record, $schedule) : null;
                $next = $why === '' && $own === null ? $due->nextDue($record, $schedule['job']) : null;
                $rows[] = [
                    $record->name,
                    $schedule['job'],
                    $schedule['every'],
                    $record->schedule_runs[$schedule['job']] ?? __('never'),
                    $next?->toIso8601String() ?? '',
                    $why !== '' ? $why : ($own ?? ''),
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
