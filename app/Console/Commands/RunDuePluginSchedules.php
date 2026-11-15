<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Plugins\Jobs\DueSchedules;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;

/**
 * teme:plugins:run-due-schedules — queues each enabled plugin's due schedules
 * (I-3445). Runs every minute on one server, without overlapping.
 */
final class RunDuePluginSchedules extends Command
{
    /** @var string */
    protected $signature = 'teme:plugins:run-due-schedules';

    /** @var string */
    protected $description = 'Queue plugin schedules that are due';

    public function handle(DueSchedules $due): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $queued = $due->run();

        if ($queued === 0) {
            $this->line('No plugin schedules were due.');
        } else {
            $this->info($queued === 1
                ? 'Queued 1 plugin schedule.'
                : "Queued {$queued} plugin schedules.");
        }

        return self::SUCCESS;
    }
}
