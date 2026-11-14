<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ai\Operations\Input\PruneAiActivityInput;
use App\Domain\Ai\Operations\PruneAiActivity;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;

/**
 * teme:ai-activity:prune — deletes AI activity rows older than 12 months, a
 * chunk at a time, through PruneAiActivity as the operator (I-318). Runs daily.
 */
final class PruneAiActivityLog extends Command
{
    /** @var string */
    protected $signature = 'teme:ai-activity:prune {--chunk=1000 : Rows per chunk}';

    /** @var string */
    protected $description = 'Delete AI activity rows older than 12 months';

    public function handle(PruneAiActivity $prune): int
    {
        // The operator, as teme:trash:purge is (docs/architecture/operations.md).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $before = now()->subMonthsNoOverflow(12);
        $chunk = max(1, min(10_000, (int) $this->option('chunk')));
        $deleted = 0;

        // A chunk that deleted fewer than it could found no more rows to delete.
        do {
            $done = $prune->execute(new PruneAiActivityInput($before, $chunk));
            $deleted += $done;
        } while ($done === $chunk);

        $this->info(trans_choice('Deleted :count AI activity row.|Deleted :count AI activity rows.', $deleted));

        return self::SUCCESS;
    }
}
