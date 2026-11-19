<?php

/**
 * teme:counts:rebuild — recomputes the status counts behind the list tabs and
 * reports any drift. With --check it only reports, and fails if there is any.
 * Runs weekly.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Contents\Operations\Input\RebuildCountsInput;
use App\Domain\Contents\Operations\RebuildCounts as RebuildCountsOperation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;

final class RebuildCounts extends Command
{
    /** @var string */
    protected $signature = 'teme:counts:rebuild {--check : Report drift without fixing it}';

    /** @var string */
    protected $description = 'Recompute the content status counts and report any drift';

    public function handle(RebuildCountsOperation $rebuild): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $check = (bool) $this->option('check');
        $result = $rebuild->execute(new RebuildCountsInput(fix: ! $check));

        if ($result['drifted'] === 0) {
            $this->info('No drift: every count matches the content.');

            return self::SUCCESS;
        }

        if ($check) {
            $this->error("{$result['drifted']} counts differ from the content. Run without --check to fix them.");

            return self::FAILURE;
        }

        $this->warn("Fixed {$result['drifted']} counts that differed from the content.");

        return self::SUCCESS;
    }
}
