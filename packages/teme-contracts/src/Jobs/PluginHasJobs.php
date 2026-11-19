<?php

/**
 * A plugin with jobs: its handlers, by declared key.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Jobs;

/**
 * A plugin with jobs: its handlers, by declared key. Implemented beside Plugin.
 *
 * @since 1.0.0
 */
interface PluginHasJobs
{
    /**
     * The handlers for the plugin's declared jobs, keyed by job key.
     *
     * @return array<string, JobHandler>
     *
     * @since 1.0.0
     */
    public function jobs(): array;
}
