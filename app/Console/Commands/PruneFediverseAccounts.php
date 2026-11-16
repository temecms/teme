<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Fediverse\Operations\Input\PruneFediverseInput;
use App\Domain\Fediverse\Operations\PruneFediverse;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;

/**
 * teme:fediverse:prune — removes the remote fediverse accounts the site no
 * longer needs, through PruneFediverse as the operator (I-058). Runs daily.
 */
final class PruneFediverseAccounts extends Command
{
    /** @var string */
    protected $signature = 'teme:fediverse:prune';

    /** @var string */
    protected $description = 'Remove remote fediverse accounts unseen for 30 days that follow nothing and wrote no reply';

    public function handle(PruneFediverse $prune): int
    {
        // The operator, as teme:inbox:prune is (operations.md, The operator).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $removed = (int) $prune->execute(new PruneFediverseInput);

        $this->info(trans_choice('Removed :count remote fediverse account.|Removed :count remote fediverse accounts.', $removed));

        return self::SUCCESS;
    }
}
