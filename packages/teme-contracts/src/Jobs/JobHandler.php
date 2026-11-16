<?php

declare(strict_types=1);

namespace Teme\Contracts\Jobs;

/**
 * A plugin's handler for one declared job.
 *
 * @since 0.9.0
 */
interface JobHandler
{
    /**
     * Runs the work for one tracked job.
     *
     * @since 0.9.0
     */
    public function handle(JobContext $context): void;
}
