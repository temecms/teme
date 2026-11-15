<?php

declare(strict_types=1);

namespace Teme\Contracts\Jobs;

/**
 * A plugin with jobs: its handlers, by declared key. Implemented beside Plugin.
 *
 * @since 0.9.0
 */
interface PluginHasJobs
{
    /**
     * The handlers for the plugin's declared jobs, keyed by job key.
     *
     * @return array<string, JobHandler>
     *
     * @since 0.9.0
     */
    public function jobs(): array;
}
