<?php

/**
 * A tracked job as a kind is told of it when it's retried or cancelled: what
 * it is, whose it is, the run it belongs to, and how far it got.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Jobs;

use App\Domain\Jobs\Models\TrackedJob;

final readonly class JobRecord
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $owner,
        public string $label,
        public ?int $startedBy,
        public bool $byOperator,
        public ?string $runType,
        public ?string $runId,
        public JobState $state,
        public ?int $total,
        public int $done,
        public ?string $message,
    ) {}

    /**
     * Returns the record of this job as it stands now.
     */
    public static function of(TrackedJob $job): self
    {
        return new self(
            id: (string) $job->id,
            kind: $job->kind,
            owner: $job->owner,
            label: $job->label,
            startedBy: $job->started_by,
            byOperator: $job->starter === 'operator',
            runType: $job->run_type,
            runId: $job->run_id,
            state: $job->state,
            total: $job->total,
            done: $job->done,
            message: $job->message,
        );
    }
}
