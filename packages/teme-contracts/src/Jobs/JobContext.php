<?php

/**
 * What a running job may read and report.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Jobs;

/**
 * What a running job may read and report.
 *
 * @since 1.0.0
 */
interface JobContext
{
    /**
     * The payload that was queued with the job.
     *
     * @return array<string, mixed>
     *
     * @since 1.0.0
     */
    public function payload(): array;

    /**
     * The attempt number, starting at 1.
     *
     * @since 1.0.0
     */
    public function attempt(): int;

    /**
     * Reports progress. Throws JobStopped when the run was cancelled or the
     * plugin was disabled.
     *
     * @since 1.0.0
     */
    public function progress(int $done, int $total, string $message = ''): void;

    /**
     * The account ID this run acts for: the person who queued it, or for a
     * schedule the administrator who enabled the plugin. Pass it to every
     * door that acts for a person. For a run with no person to act for, such
     * as one the operator queued, it throws, and the run fails at once
     * without another try.
     *
     * @since 1.0.0
     */
    public function personId(): string;

    /**
     * Sets the closing line shown when the run finishes. Without a call, it
     * stays "Finished."
     *
     * @since next
     */
    public function summary(string $message): void;

    /**
     * The ids of this plugin's own permissions the person this run acts for
     * holds, such as `manage-lines`; never another plugin's, and never a role.
     *
     * @return list<string>
     *
     * @since next
     */
    public function permissions(): array;
}
