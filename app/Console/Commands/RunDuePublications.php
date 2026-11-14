<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Contents\Exceptions\SchedulerCannotPublishException;
use App\Domain\Contents\Operations\ClaimDuePublications;
use App\Domain\Contents\Operations\FailScheduledPublication;
use App\Domain\Contents\Operations\HoldScheduledPublication;
use App\Domain\Contents\Operations\Input\ClaimDuePublicationsInput;
use App\Domain\Contents\Operations\Input\ScheduledPublicationInput;
use App\Domain\Contents\Operations\Input\ScheduledPublicationOutcomeInput;
use App\Domain\Contents\Operations\RunScheduledPublication;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Users\Actors;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * teme:content:run-due-publications — claims the scheduled publications that
 * are due and runs each in its own transaction, so one failure never undoes
 * another. A held or failed row is recorded, not an error of the command.
 * Runs every minute.
 */
final class RunDuePublications extends Command
{
    /** @var string */
    protected $signature = 'teme:content:run-due-publications';

    /** @var string */
    protected $description = 'Publish the scheduled publications that are due';

    public function handle(
        ClaimDuePublications $claim,
        RunScheduledPublication $run,
        HoldScheduledPublication $hold,
        FailScheduledPublication $fail,
        Actors $actors,
    ): int {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $rows = $claim->execute(new ClaimDuePublicationsInput((int) config('editor.publications_per_run', 50)));

        // Each scheduler, with their roles and permissions, read once for the
        // pass (B-200): a permission taken away mid-pass applies from the next.
        // Forgotten whatever happens, so nothing outlives the pass.
        $actors->keep(...array_values(array_unique(array_filter(array_column($rows, 'createdBy')))));

        try {
            foreach ($rows as $row) {
                try {
                    $result = $run->execute(new ScheduledPublicationInput($row['id'], $row['createdBy']));
                } catch (SchedulerCannotPublishException $refused) {
                    $hold->execute(new ScheduledPublicationOutcomeInput($row['id'], $refused->reason));
                    Log::warning('A scheduled publication is held.', ['scheduled_publication' => $row['id'], 'reason' => $refused->reason]);
                    $this->warn("Held {$row['id']}: {$refused->reason}.");

                    continue;
                } catch (Throwable $failure) {
                    $fail->execute(new ScheduledPublicationOutcomeInput($row['id'], $failure->getMessage()));
                    $this->error("Failed {$row['id']}: {$failure->getMessage()}");

                    continue;
                }

                if (! $result['published']) {
                    $this->line("Skipped {$row['id']}: it was no longer waiting to run.");

                    continue;
                }

                $minutes = intdiv($result['lateSeconds'], 60);
                $late = $result['lateSeconds'] > 60 ? ', '.$minutes.' '.($minutes === 1 ? 'minute' : 'minutes').' late' : '';
                $this->info("Published {$row['id']}{$late}.");
            }
        } finally {
            $actors->forget();
        }

        return self::SUCCESS;
    }
}
