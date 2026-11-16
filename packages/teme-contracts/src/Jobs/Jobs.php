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
     *
     * @since 0.9.0
     */
    public function queue(string $key, array $payload = [], ?string $label = null): string;
}
