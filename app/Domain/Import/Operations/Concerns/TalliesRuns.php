<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations\Concerns;

use App\Domain\Cache\PageTags;
use App\Domain\Import\ImportMessageLevel;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\Models\ImportRun;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Cache\CacheKeysFor;

/**
 * A run's counts, from the map and its messages when a phase or the run
 * ends, never one object at a time, so parallel workers never queue on the
 * run's row; and the one purge of everything a run ends with (I-332).
 */
trait TalliesRuns
{
    private function tally(ImportRun $run): void
    {
        $counts = DB::table('import_map')->where('run_id', $run->id)
            ->selectRaw('outcome, count(*) AS total')->groupBy('outcome')
            ->pluck('total', 'outcome');

        $run->created_count = (int) ($counts[ImportOutcome::Created->value] ?? 0);
        $run->updated_count = (int) ($counts[ImportOutcome::Updated->value] ?? 0);
        $run->skipped_count = (int) ($counts[ImportOutcome::Skipped->value] ?? 0);
        $run->failed_count = (int) ($counts[ImportOutcome::Failed->value] ?? 0);
        $run->warning_count = DB::table('import_messages')->where('run_id', $run->id)->where('level', ImportMessageLevel::Warning->value)->count();
    }

    /**
     * Every stored page goes, after the run's last commit.
     */
    private function purgeEverything(): void
    {
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, PageTags::EVERYTHING, [PageTags::EVERYTHING]));
    }
}
