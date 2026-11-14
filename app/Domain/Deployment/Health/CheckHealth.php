<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Throwable;

/**
 * What /up checks before it answers 200 (I-378): the database answers and
 * holds every migration the code ships, in one query, and every folder
 * Teme writes to can be written. Any problem fails the check, and Laravel's
 * route reports it and answers 500 with nothing of it in the body.
 */
final readonly class CheckHealth
{
    public function __construct(private PendingMigrations $migrations) {}

    /**
     * @throws HealthCheckFailed naming every problem
     */
    public function handle(DiagnosingHealth $event): void
    {
        $problems = [];

        try {
            $pending = $this->migrations->count();

            if ($pending > 0) {
                $problems[] = $pending === 1 ? "1 migration hasn't run on the database." : "{$pending} migrations haven't run on the database.";
            }
        } catch (Throwable $exception) {
            // The driver's own message, without the query's list of names.
            $problems[] = "The database didn't answer: ".$exception::class.': '.($exception->getPrevious() ?? $exception)->getMessage();
        }

        foreach (HealthFolders::unwritable() as $path) {
            $problems[] = "{$path} can't be written.";
        }

        if ($problems !== []) {
            throw HealthCheckFailed::because($problems);
        }
    }
}
