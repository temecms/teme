<?php

/**
 * How to queue a plugin's job: after a delay, and once while one waits.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Jobs;

use InvalidArgumentException;

/**
 * How to queue a plugin's job: after a delay, and once while one waits.
 *
 * @since 1.0.0
 */
final readonly class QueueOptions
{
    /** The longest delay, in seconds (one day). */
    private const int MOST_DELAY = 86_400;

    /** A unique key's pattern. */
    private const string UNIQUE_KEY = '/^[a-z0-9][a-z0-9:._-]{0,63}$/D';

    /**
     * @param  int  $delaySeconds  0 to 86_400: the run starts no sooner
     * @param  string|null  $uniqueKey  while a run of this job with this key waits, queue() returns that run's ID and queues nothing; its payload stands, the new one is dropped
     *
     * @throws InvalidArgumentException for a delay outside 0 to 86_400, or a key not matching ^[a-z0-9][a-z0-9:._-]{0,63}$
     *
     * @since 1.0.0
     */
    public function __construct(public int $delaySeconds = 0, public ?string $uniqueKey = null)
    {
        if ($delaySeconds < 0 || $delaySeconds > self::MOST_DELAY) {
            throw new InvalidArgumentException('A job’s delay must be from 0 to 86400 seconds.');
        }

        if ($uniqueKey !== null && preg_match(self::UNIQUE_KEY, $uniqueKey) !== 1) {
            throw new InvalidArgumentException('A job’s unique key must be 1 to 64 lower-case letters, digits and the characters : . _ -, starting with a letter or digit.');
        }
    }
}
