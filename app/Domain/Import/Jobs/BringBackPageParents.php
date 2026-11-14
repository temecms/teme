<?php

declare(strict_types=1);

namespace App\Domain\Import\Jobs;

use App\Domain\Contents\Tree\SubtreeWords;
use App\Domain\Import\Operations\Input\PlaceImportedPageInput;
use App\Domain\Import\Operations\PlaceImportedPage;
use App\Domain\Import\PlacedPage;
use App\Domain\Import\Queries\ImportedPageTree;
use App\Domain\Import\Queries\ImportRuns;
use App\Domain\Import\Queries\ImportSources;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\FinishJob;
use App\Domain\Jobs\Operations\Input\FailJobInput;
use App\Domain\Jobs\Operations\Input\FinishJobInput;
use App\Domain\Jobs\Operations\Input\ReportJobProgressInput;
use App\Domain\Jobs\Operations\ReportJobProgress;
use App\Domain\Support\Security\JobActor;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One step of bringing back a site's page parents (I-3363): the next 200 of
 * its imported pages, parents first, each through PlaceImportedPage as the
 * person who asked, then the job's progress, then the next step or the end.
 * While a run of the site is running it waits, so the two never lock the
 * same pages in different orders. It reads nothing from the old site.
 */
final class BringBackPageParents implements ShouldQueue
{
    use Queueable;

    /** The pages one step places. */
    public const int PAGES = 200;

    /** How long a step waits for a run of the site to end before it looks again, in seconds. */
    public const int WAIT = 60;

    /** Real errors before the step fails; waiting for a run is never one. */
    public int $maxExceptions = 3;

    /** When this step stops waiting for a run of the site, as a Unix time: a day after it was queued. */
    public readonly int $until;

    /** @var list<int> */
    public array $backoff = [5, 30];

    /** Under the database queue's retry_after of 90 seconds, so no second worker takes the step while this one has it. */
    public int $timeout = 75;

    /**
     * @param  string|null  $cursor  the last page placed, as "depth:WordPress ID", or null at the start
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $sourceId,
        public readonly ?string $cursor,
        public readonly JobActor $actor,
        public readonly int $visited = 0,
        public readonly int $placed = 0,
        public readonly int $skipped = 0,
    ) {
        $this->until = now()->addDay()->getTimestamp();
    }

    /**
     * How long the step may wait for a run of the site to end: a day, as
     * long as a large site's import, so a wait never counts as a failure.
     */
    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::createFromTimestamp($this->until);
    }

    public function handle(ImportRuns $runs, ImportedPageTree $tree, ImportSources $sources, SubtreeWords $words): void
    {
        $this->actor->bind();
        $actorId = $this->actor->userId;

        if ($runs->running($this->sourceId) !== null) {
            $this->release(self::WAIT);

            return;
        }

        // A cancelled or ended job answers false, and nothing more is done (I-104).
        if (! app(ReportJobProgress::class)->execute(new ReportJobProgressInput($this->jobId, actorId: $actorId))) {
            return;
        }

        [$depth, $after] = $this->cursor === null ? [0, 0] : array_map(intval(...), explode(':', $this->cursor, 2) + [1 => '0']);
        $rows = $tree->batch($this->sourceId, $depth, $after, self::PAGES);
        [$placed, $skipped] = [$this->placed, $this->skipped];

        foreach ($rows as $row) {
            $outcome = app(PlaceImportedPage::class)->execute(new PlaceImportedPageInput($this->sourceId, $row['object_id'], $row['rank'], $actorId));

            if ($outcome->status === PlacedPage::LEFT) {
                $skipped++;
            } elseif ($row['wp_parent'] > 0) {
                $placed++;
            }
        }

        $visited = $this->visited + count($rows);
        $total = max($visited, $tree->pages($this->sourceId));

        if (! app(ReportJobProgress::class)->execute(new ReportJobProgressInput($this->jobId, advancedBy: count($rows), total: $total, message: $words->progress($visited, $total), actorId: $actorId))) {
            return;
        }

        if (count($rows) === self::PAGES) {
            $last = $rows[count($rows) - 1];
            self::dispatch($this->jobId, $this->sourceId, $last['depth'].':'.$last['object_id'], $this->actor, $visited, $placed, $skipped);

            return;
        }

        $site = (string) $sources->find($this->sourceId)?->address;
        $finished = trans_choice('Put :placed page back under their parents.|Put :placed pages back under their parents.', $placed, ['placed' => $placed]);

        if ($skipped > 0) {
            $finished .= ' '.__(':skipped stayed where they are; php artisan teme:import:page-parents :site --notes says why.', ['skipped' => $skipped, 'site' => $site]);
        }

        app(FinishJob::class)->execute(new FinishJobInput($this->jobId, $finished, $actorId));
    }

    /**
     * Ends the job as failed once the queue has given up on the step; Retry
     * starts it again, and the pages already placed stay placed.
     */
    public function failed(?Throwable $exception): void
    {
        app(FailJob::class)->execute(new FailJobInput($this->jobId, __('Bringing back page parents stopped. Retry starts it again.'), $this->actor->userId));
    }
}
