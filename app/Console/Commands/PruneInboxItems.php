<?php

/**
 * teme:inbox:prune — deletes inbox items that arrived more than 90 days ago,
 * read or not, a chunk at a time, through PruneInbox as the operator (I-221).
 * Runs daily.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inbox\Operations\Input\PruneInboxInput;
use App\Domain\Inbox\Operations\PruneInbox;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;

final class PruneInboxItems extends Command
{
    /** @var string */
    protected $signature = 'teme:inbox:prune {--chunk=1000 : Items per chunk}';

    /** @var string */
    protected $description = 'Delete inbox items that arrived more than 90 days ago';

    public function handle(PruneInbox $prune): int
    {
        // The operator, as teme:trash:purge is (operations.md, The operator).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $arrivedBefore = now()->subDays(90)->toImmutable();
        $chunk = max(1, min(10_000, (int) $this->option('chunk')));
        $deleted = 0;

        // A chunk that deleted fewer than it could found no more rows to delete.
        do {
            $done = $prune->execute(new PruneInboxInput($arrivedBefore, $chunk));
            $deleted += $done;
        } while ($done === $chunk);

        $this->info(trans_choice('Deleted :count inbox item.|Deleted :count inbox items.', $deleted));

        return self::SUCCESS;
    }
}
