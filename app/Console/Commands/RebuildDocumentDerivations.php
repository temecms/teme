<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Contents\Operations\Input\RebuildDocumentDerivationsInput;
use App\Domain\Contents\Operations\RebuildDocumentDerivations as RebuildDocumentDerivationsOperation;
use App\Domain\Media\Queries\ContentIdsInOrder;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;

/**
 * teme:content:rebuild-derivations — fills in the plain text and word count
 * of revisions written before 1.5b, and the search text of published items,
 * a chunk at a time, as the operator (I-422). With --check it only counts,
 * and fails when anything needs filling in. The operator runs it once on
 * each site after deploying 1.5c; running it again changes nothing.
 */
final class RebuildDocumentDerivations extends Command
{
    /** @var string */
    protected $signature = 'teme:content:rebuild-derivations
        {--check : Only count what needs filling in, and fail if anything does}
        {--chunk=500 : Content items per chunk}';

    /** @var string */
    protected $description = 'Fill in derived document values for revisions written before they existed';

    public function handle(RebuildDocumentDerivationsOperation $rebuild): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $fix = ! $this->option('check');
        $chunk = max(1, (int) $this->option('chunk'));
        [$last, $revisions, $searchable] = [null, 0, 0];

        while (($ids = ContentIdsInOrder::after($last, $chunk)) !== []) {
            /** @var array{revisions: int, searchable: int} $result */
            $result = $rebuild->execute(new RebuildDocumentDerivationsInput($ids, $fix));
            $revisions += $result['revisions'];
            $searchable += $result['searchable'];
            $last = $ids[array_key_last($ids)];
        }

        if ($revisions === 0 && $searchable === 0) {
            $this->info('Nothing to fill in: every revision has its plain text and every published item its search text.');

            return self::SUCCESS;
        }

        if (! $fix) {
            $this->error("{$revisions} revisions and {$searchable} published items need filling in. Run without --check to fill them.");

            return self::FAILURE;
        }

        $this->info("Filled in {$revisions} revisions and the search text of {$searchable} published items.");

        return self::SUCCESS;
    }
}
