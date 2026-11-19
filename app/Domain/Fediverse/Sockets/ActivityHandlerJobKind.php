<?php

/**
 * A plugin's handler for an inbox activity as a kind of tracked work
 * (docs/plans/fediverse-publishing.md, follow-up R7): retrying runs the
 * handler again with the activity kept on the job, still as the
 * administrator who enabled the plugin; cancelling leaves nothing queued
 * to drop.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Sockets;

use App\Domain\Fediverse\Sockets\Jobs\RunActivityHandler;
use App\Domain\Jobs\JobKind;
use App\Domain\Jobs\JobRecord;

final readonly class ActivityHandlerJobKind implements JobKind
{
    /** The kind's key. */
    public const string KEY = 'fediverse.handler';

    /** The run type its jobs carry, with the handler's plugin code. */
    public const string RUN_TYPE = 'fediverse-handler';

    public function key(): string
    {
        return self::KEY;
    }

    public function retry(JobRecord $job, ?string $actorId): void
    {
        RunActivityHandler::dispatch($job->id);
    }

    public function cancel(JobRecord $job, ?string $actorId): void
    {
        // Nothing is queued apart from the run, which finds the job ended and does nothing.
    }
}
