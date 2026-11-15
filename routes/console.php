<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\Heartbeats;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The scheduler's heartbeat, first, so each minute's run writes it before
// anything long holds the run up (I-3112).
Schedule::call(fn (Heartbeats $heartbeats) => $heartbeats->beat(Heartbeats::SCHEDULER))->everyMinute()->name('teme:heartbeat');

Schedule::command('teme:security-log:prune')->daily();
Schedule::command('teme:ai-activity:prune')->daily();
Schedule::command('teme:counts:rebuild')->weekly();

Schedule::command('teme:backup:due')->everyMinute()->withoutOverlapping(10)->onOneServer();

Schedule::command('teme:trash:purge')->daily();
Schedule::command('teme:comments:erase-addresses')->daily();
Schedule::command('teme:content:run-due-publications')->everyMinute()->withoutOverlapping();
Schedule::command('teme:cache:prune')->everyMinute()->withoutOverlapping();
Schedule::command('teme:redirects:count')->everyMinute()->withoutOverlapping();
Schedule::command('teme:csp:record')->everyMinute()->withoutOverlapping();
Schedule::command('teme:inbox:prune')->daily();
Schedule::command('teme:privacy:prune')->daily();
Schedule::command('teme:jobs:prune')->daily();
Schedule::command('teme:fediverse:prune')->daily();

Schedule::command('teme:updates:check')->hourly()->withoutOverlapping(10)->onOneServer();
