<?php

/**
 * teme:csp:record — adds the policy reports browsers sent, from the spool a
 * run ago, to their table through RecordPolicyReports as the operator
 * (I-3375). Runs every minute.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Site\Policy\Reports\Operations\Input\RecordPolicyReportsInput;
use App\Domain\Site\Policy\Reports\Operations\RecordPolicyReports;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;

final class RecordPolicyReportsCommand extends Command
{
    /** @var string */
    protected $signature = 'teme:csp:record';

    /** @var string */
    protected $description = 'Add the policy reports browsers sent since the last run to the table';

    public function handle(RecordPolicyReports $record): int
    {
        // The operator (docs/architecture/operations.md, The operator).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $read = $record->execute(new RecordPolicyReportsInput);

        $this->info(__('Policy reports read: :count.', ['count' => $read]));

        return self::SUCCESS;
    }
}
