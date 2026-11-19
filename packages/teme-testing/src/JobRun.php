<?php

/**
 * One plugin job as Tools › Jobs records it once runJob() returns: its ID,
 * its state (finished, failed, cancelled, or queued when it hasn't run), and
 * its message, the first line of the failure for a failed one.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Testing;

final readonly class JobRun
{
    public const string FINISHED = 'finished';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';

    public const string QUEUED = 'queued';

    public function __construct(
        public string $id,
        public string $state,
        public ?string $message,
    ) {}
}
