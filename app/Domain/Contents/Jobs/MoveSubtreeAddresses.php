<?php

declare(strict_types=1);

namespace App\Domain\Contents\Jobs;

use App\Domain\Addresses\Operations\Input\RebuildPathsInput;
use App\Domain\Addresses\Operations\RebuildPaths;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Tree\ContentTree;
use App\Domain\Contents\Tree\SubtreeMoves;
use App\Domain\Contents\Tree\SubtreeWords;
use App\Domain\Jobs\Operations\FailJob;
use App\Domain\Jobs\Operations\FinishJob;
use App\Domain\Jobs\Operations\Input\FailJobInput;
use App\Domain\Jobs\Operations\Input\FinishJobInput;
use App\Domain\Jobs\Operations\Input\ReportJobProgressInput;
use App\Domain\Jobs\Operations\ReportJobProgress;
use App\Domain\Support\Security\JobActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One step of moving the pages under a page in the background (I-3363,
 * I-104): the next 500 of them that have an address, by ID, through
 * RebuildPaths' subtree mode as the person who made the change, then the
 * job's progress, then the next step or the end. Every step is idempotent.
 */
final class MoveSubtreeAddresses implements ShouldQueue
{
    use Queueable;

    /** The pages one step moves. */
    public const int PAGES = 500;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    /** Under the database queue's retry_after of 90 seconds, so no second worker takes the step while this one has it. */
    public int $timeout = 75;

    /**
     * @param  list<string>  $keptTitles  the titles of the pages kept so far, at most five
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $rootId,
        public readonly string $afterId,
        public readonly JobActor $actor,
        public readonly int $kept = 0,
        public readonly array $keptTitles = [],
    ) {}

    public function handle(ContentTree $tree, SubtreeWords $words): void
    {
        $this->actor->bind();
        $actorId = $this->actor->userId;
        $root = Content::withTrashed()->find($this->rootId);

        if ($root === null) {
            app(FailJob::class)->execute(new FailJobInput($this->jobId, $words->rootGone(), $actorId));

            return;
        }

        // A cancelled or ended job answers false, and nothing more is done (I-104).
        if (! app(ReportJobProgress::class)->execute(new ReportJobProgressInput($this->jobId, actorId: $actorId))) {
            return;
        }

        $all = collect($tree->descendants($this->rootId))->filter(fn (object $row): bool => $row->shown)->pluck('id')->sort()->values();
        $ids = $all->filter(fn (string $id): bool => strcmp($id, $this->afterId) > 0)->take(self::PAGES)->values()->all();
        $kept = $this->kept;
        $keptTitles = $this->keptTitles;

        if ($ids !== []) {
            $moves = app(SubtreeMoves::class);
            $keptBefore = count($moves->kept());

            try {
                app(RebuildPaths::class)->execute(new RebuildPathsInput('content', $ids, $actorId, under: $this->rootId));
            } catch (AuthorizationException) {
                $name = $actorId === null ? '' : (string) DB::table('users')->where('id', $actorId)->value('name');
                app(FailJob::class)->execute(new FailJobInput($this->jobId, $words->notPermitted($name, (string) $root->title), $actorId));

                return;
            }

            $newlyKept = array_slice($moves->kept(), $keptBefore);
            $kept += count($newlyKept);
            $keptTitles = array_slice([...$keptTitles, ...array_column($newlyKept, 'title')], 0, SubtreeWords::NAMED);
        }

        $last = $ids === [] ? null : $ids[array_key_last($ids)];
        $done = $last === null ? $all->count() : $all->search($last) + 1;

        if (! app(ReportJobProgress::class)->execute(new ReportJobProgressInput($this->jobId, advancedBy: count($ids), total: $all->count(), message: $words->progress($done, $all->count()), actorId: $actorId))) {
            return;
        }

        if ($last !== null && $done < $all->count()) {
            self::dispatch($this->jobId, $this->rootId, $last, $this->actor, $kept, $keptTitles);

            return;
        }

        app(FinishJob::class)->execute(new FinishJobInput($this->jobId, $words->finished($all->count() - $kept, (string) $root->title, $kept, $keptTitles), $actorId));
    }

    /**
     * Ends the job as failed once the queue has given up on the step; Retry
     * starts it again, and the pages already moved stay moved.
     */
    public function failed(?Throwable $exception): void
    {
        app(FailJob::class)->execute(new FailJobInput($this->jobId, __('The pages under it stopped moving. Retry carries on.'), $this->actor->userId));
    }
}
