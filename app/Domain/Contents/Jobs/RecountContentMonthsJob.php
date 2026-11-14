<?php

declare(strict_types=1);

namespace App\Domain\Contents\Jobs;

use App\Domain\Contents\Operations\Input\RecountContentMonthsInput;
use App\Domain\Contents\Operations\RecountContentMonths;
use App\Domain\Support\Security\JobActor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Counts the month rows of content_counts again after the site's time zone
 * changes (I-3131). It carries whoever changed the zone, an administrator or
 * the operator, whom the Operation authorizes.
 */
final class RecountContentMonthsJob implements ShouldQueue
{
    use Queueable;

    /** Seconds one run may take, inside the default connection's window. */
    public int $timeout = 60;

    public function __construct(public readonly JobActor $actor) {}

    public function handle(): void
    {
        $this->actor->bind();

        app(RecountContentMonths::class)->execute(new RecountContentMonthsInput($this->actor->userId));
    }
}
