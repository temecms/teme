<?php

/**
 * Tells administrators of a production site when a queue's jobs have
 * waited ten minutes with no worker taking them (I-3112), naming each
 * such queue, so a missing worker is found before its work is missed.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Status\Checks;

use App\Domain\Deployment\Health\UntakenJobs;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;

final readonly class QueueWorkerRunning implements StatusCheck
{
    /** Below the scheduler's banner, above every other. */
    public const int PRIORITY = 105;

    public function __construct(private UntakenJobs $jobs) {}

    /**
     * The check's stable key, matched by Home's summary rows.
     *
     * @since next
     */
    public function key(): string
    {
        return 'queue-worker-running';
    }

    public function ability(): string
    {
        return 'settings.general.manage';
    }

    /**
     * @since 0.8.0-alpha.1
     */
    public function label(): string
    {
        return __('Background jobs');
    }

    public function banner(): ?StatusBanner
    {
        if (! app()->isProduction()) {
            return null;
        }

        try {
            $untaken = $this->jobs->find();
        } catch (QueryException) {
            // No heartbeats table yet: code ahead of its migration, which /up reports (I-378).
            return null;
        }

        if ($untaken === []) {
            return null;
        }

        $queues = array_keys($untaken);
        $oldest = min(array_map(fn (CarbonInterface $since): int => $since->getTimestamp(), $untaken));
        $waited = CarbonImmutable::createFromTimestamp($oldest)->diffForHumans(null, CarbonInterface::DIFF_ABSOLUTE);
        $last = array_pop($queues);
        $named = $queues === [] ? "the {$last} queue" : 'the '.implode(', ', $queues)." and {$last} queues";
        $for = $queues === [] ? 'it' : 'each';

        return new StatusBanner(
            'error',
            "Uploaded images' sizes and other background work are waiting: jobs on {$named} have waited {$waited} with no worker taking them. Whoever runs the server should start a worker for {$for}.",
            null,
            null,
            self::PRIORITY,
        );
    }
}
