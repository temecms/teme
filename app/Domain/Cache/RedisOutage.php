<?php

declare(strict_types=1);

namespace App\Domain\Cache;

use Throwable;

/**
 * Remembers, for the rest of one request, that the page cache's Redis
 * connection could not be made, so a Valkey that drops packets costs a page
 * one connect timeout, never one for each time the store is asked. Scoped to
 * a request, never static (I-087).
 */
final class RedisOutage
{
    private ?Throwable $failure = null;

    /**
     * Notes that the connection could not be made.
     */
    public function noteFailure(Throwable $failure): void
    {
        $this->failure ??= $failure;
    }

    /**
     * The failure the connection was given up with, or null while it has not failed.
     */
    public function failure(): ?Throwable
    {
        return $this->failure;
    }
}
