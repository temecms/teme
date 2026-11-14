<?php

declare(strict_types=1);

namespace App\Domain\Jobs;

use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Support\Security\Operator;
use App\Domain\Users\Actors;

/**
 * Whose business a tracked job is (I-1714): whoever started it, the
 * operator, and those who hold jobs.manage. They may report on it, retry it
 * and cancel it; nobody else may.
 */
final readonly class JobAccess
{
    /** The permission that opens Tools › Jobs and lets its holder act on any job. */
    public const string MANAGE = 'jobs.manage';

    public function __construct(private Actors $actors) {}

    /**
     * Whether this actor may report on, retry or cancel the job: a null actor
     * only while the operator is present (docs/architecture/operations.md, The operator).
     */
    public function allows(TrackedJob $job, ?string $actorId): bool
    {
        if ($actorId === null) {
            return Operator::isPresent();
        }

        if ($job->started_by !== null && (string) $job->started_by === $actorId) {
            return true;
        }

        return $this->actors->find($actorId)?->can(self::MANAGE) === true;
    }
}
