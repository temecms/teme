<?php

/**
 * teme:redirects:count — writes the redirect hits and the addresses not
 * found that readers met, from the spool a run ago, through
 * RecordAddressCounts as the operator (I-297, I-298). Runs every minute.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Redirects\NotFoundLogSection;
use App\Domain\Redirects\Operations\Input\RecordAddressCountsInput;
use App\Domain\Redirects\Operations\RecordAddressCounts;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;

final class CountAddresses extends Command
{
    /** @var string */
    protected $signature = 'teme:redirects:count';

    /** @var string */
    protected $description = 'Write the redirect hits and the addresses not found that readers met since the last run';

    public function handle(RecordAddressCounts $record): int
    {
        // The operator (docs/architecture/operations.md, The operator).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        // The site's own size and ignore list, read here and never on a reader's request (I-874).
        $read = $record->execute(new RecordAddressCountsInput(keep: NotFoundLogSection::size(), ignore: NotFoundLogSection::patterns()));

        $this->info(__('Redirect hits: :hits. “Not found” answers: :missing.', ['hits' => $read['hits'], 'missing' => $read['notFound']]));

        return self::SUCCESS;
    }
}
