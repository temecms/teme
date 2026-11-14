<?php

declare(strict_types=1);

namespace App\Domain\Contents\Tree;

use App\Domain\Contents\Jobs\MoveSubtreeAddresses;
use App\Domain\Jobs\JobKind;
use App\Domain\Jobs\JobRecord;
use App\Domain\Jobs\JobState;
use App\Domain\Support\Security\JobActor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Moving the pages under a page in the background as a kind of tracked job
 * (I-3363, I-104): retrying starts again from the first page, since every
 * step is idempotent, as whoever retries; cancelling leaves nothing to drop.
 */
final readonly class SubtreeJobKind implements JobKind
{
    /** The kind's key. */
    public const string KEY = 'content.subtree';

    /** The run type its jobs carry, with the ID of the page they are under. */
    public const string RUN_TYPE = 'content-subtree';

    /**
     * Whether a move of the pages under this page hasn't ended: waiting,
     * running, or failed and waiting for Retry (I-3363).
     */
    public static function isMoving(string $rootId): bool
    {
        return self::moving()->where('run_id', $rootId)->exists();
    }

    /**
     * The moves that haven't ended, for a query to leave their pages out.
     */
    public static function moving(): Builder
    {
        return DB::table('tracked_jobs')
            ->where('kind', self::KEY)
            ->where('run_type', self::RUN_TYPE)
            ->whereIn('state', [JobState::Waiting->value, JobState::Running->value, JobState::Failed->value]);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function retry(JobRecord $job, ?string $actorId): void
    {
        MoveSubtreeAddresses::dispatch($job->id, (string) $job->runId, '', JobActor::of($actorId));
    }

    public function cancel(JobRecord $job, ?string $actorId): void
    {
        // Nothing is queued apart from the next step, whose first report answers false for a cancelled job (I-104).
    }
}
