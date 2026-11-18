<?php

/**
 * teme:jobs:prune — deletes jobs that ended more than 90 days ago, a chunk
 * at a time, through PruneJobs as the operator (I-104). Runs daily.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Jobs\Operations\Input\PruneJobsInput;
use App\Domain\Jobs\Operations\PruneJobs;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;

final class PruneEndedJobs extends Command
{
    /** @var string */
    protected $signature = 'teme:jobs:prune {--chunk=1000 : Jobs per chunk}';

    /** @var string */
    protected $description = 'Delete jobs that ended more than 90 days ago';

    public function handle(PruneJobs $prune): int
    {
        // The operator, as teme:trash:purge is (operations.md, The operator).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $endedBefore = now()->subDays(90)->toImmutable();
        $chunk = max(1, min(10_000, (int) $this->option('chunk')));
        $deleted = 0;

        // A chunk that deleted fewer than it could found no more rows to delete.
        do {
            $done = $prune->execute(new PruneJobsInput($endedBefore, $chunk));
            $deleted += $done;
        } while ($done === $chunk);

        $this->info(trans_choice('Deleted :count ended job.|Deleted :count ended jobs.', $deleted));

        return self::SUCCESS;
    }
}
