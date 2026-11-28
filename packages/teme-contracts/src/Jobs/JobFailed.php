<?php

/**
 * Thrown by a job handler to end the run for good, without another try.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Jobs;

use RuntimeException;

/**
 * Thrown by a job handler to end the run for good, without another try.
 *
 * @since next
 */
final class JobFailed extends RuntimeException
{
    /**
     * Ends this run as failed with this reason (first line, at most 200
     * characters, or "It failed." when blank), with no retry, whatever
     * `tries` says.
     *
     * @since next
     */
    public static function permanently(string $reason): self
    {
        return new self($reason);
    }
}
