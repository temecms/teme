<?php

/**
 * Bringing back a site's page parents as a kind of tracked job (I-3363,
 * I-104): retrying starts again from the first page, as whoever retries,
 * since placing a page twice changes nothing; cancelling leaves nothing to
 * drop.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Tracking;

use App\Domain\Import\Jobs\BringBackPageParents;
use App\Domain\Jobs\JobKind;
use App\Domain\Jobs\JobRecord;
use App\Domain\Support\Security\JobActor;

final readonly class PageParentsJobKind implements JobKind
{
    /** The kind's key. */
    public const string KEY = 'import.page-parents';

    /** The run type its jobs carry, with the ID of the site. */
    public const string RUN_TYPE = 'import-source';

    public function key(): string
    {
        return self::KEY;
    }

    public function retry(JobRecord $job, ?string $actorId): void
    {
        BringBackPageParents::dispatch($job->id, (string) $job->runId, null, JobActor::of($actorId));
    }

    public function cancel(JobRecord $job, ?string $actorId): void
    {
        // Nothing is queued apart from the next step, whose first report answers false for a cancelled job (I-104).
    }
}
