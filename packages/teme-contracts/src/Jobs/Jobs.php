<?php

declare(strict_types=1);

namespace Teme\Contracts\Jobs;

/**
 * Queues one of the calling plugin's declared jobs. Offered only when the
 * plugin was granted `jobs`.
 *
 * @since 0.9.0
 */
interface Jobs
{
    /**
     * Queues the declared job. Returns the tracked job's ID.
     *
     * @param  array<string, mixed>  $payload  JSON-ready data, at most 64 KB.
     * @param  QueueOptions|null  $options  a delay, and a key that queues it once while one waits (@since 0.9.0)
     *
     * @since 0.9.0
     */
    public function queue(string $key, array $payload = [], ?string $label = null, ?QueueOptions $options = null): string;
}
