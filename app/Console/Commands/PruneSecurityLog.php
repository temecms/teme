<?php

/**
 * `teme:security-log:prune`: deletes security events older than the retention
 * period. Runs daily.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Security\SecurityLog;
use Illuminate\Console\Command;

final class PruneSecurityLog extends Command
{
    /** @var string */
    protected $signature = 'teme:security-log:prune {--days=365 : Keep this many days}';

    /** @var string */
    protected $description = 'Delete security events older than the retention period';

    public function handle(SecurityLog $log): int
    {
        $deleted = $log->prune(max(1, (int) $this->option('days')));

        $this->info("Deleted {$deleted} security events.");

        return self::SUCCESS;
    }
}
