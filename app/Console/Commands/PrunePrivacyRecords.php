<?php

/**
 * teme:privacy:prune — keeps privacy requests only as long as they are
 * needed, through PrunePrivacyRequests as the operator (I-3375). Runs daily.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Privacy\Operations\Input\PrunePrivacyRequestsInput;
use App\Domain\Privacy\Operations\PrunePrivacyRequests;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;

final class PrunePrivacyRecords extends Command
{
    /** @var string */
    protected $signature = 'teme:privacy:prune';

    /** @var string */
    protected $description = 'Delete old export files, clear old requests\' addresses, and delete requests older than a year';

    public function handle(PrunePrivacyRequests $prune): int
    {
        // The operator, as teme:comments:erase-addresses is (operations.md, The operator).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $counts = $prune->execute(new PrunePrivacyRequestsInput);

        $this->info(__('Deleted :files export file(s), cleared :hashed address(es), and deleted :deleted old request(s).', [
            'files' => $counts['files'],
            'hashed' => $counts['hashed'],
            'deleted' => $counts['deleted'],
        ]));

        return self::SUCCESS;
    }
}
