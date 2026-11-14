<?php

declare(strict_types=1);

namespace App\Domain\Jobs;

/**
 * A kind of background work Teme can retry and cancel (I-104): an import,
 * Select all matching, a backup, and in time a plugin's work. The part that
 * owns the work tags it under JobKinds::TAG from its own provider.
 */
interface JobKind
{
    /**
     * The kind's key, as tracked jobs keep it: lower-case words, hyphens
     * inside, dots between (JobKinds::KEY), at most 64 characters.
     */
    public function key(): string;

    /**
     * Queues the work again, to carry on from where it stopped. Called once
     * the retry has committed, with who asked: a person's ID, or null for the
     * operator. The kind chooses whom the work runs as, its starter or who
     * asked; work the operator started and a person retries carries on as
     * that person, since a request never makes the operator (I-392).
     */
    public function retry(JobRecord $job, ?string $actorId): void;

    /**
     * Drops whatever the kind keeps queued for the job, if anything. Called
     * once the cancel has committed; work mid-step stops at its next report.
     */
    public function cancel(JobRecord $job, ?string $actorId): void;
}
