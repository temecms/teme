<?php

declare(strict_types=1);

namespace Teme\Contracts\Jobs;

/**
 * What a running job may read and report.
 *
 * @since 0.9.0
 */
interface JobContext
{
    /**
     * The payload that was queued with the job.
     *
     * @return array<string, mixed>
     *
     * @since 0.9.0
     */
    public function payload(): array;

    /**
     * The attempt number, starting at 1.
     *
     * @since 0.9.0
     */
    public function attempt(): int;

    /**
     * Reports progress. Throws JobStopped when the run was cancelled or the
     * plugin was disabled.
     *
     * @since 0.9.0
     */
    public function progress(int $done, int $total, string $message = ''): void;
}
