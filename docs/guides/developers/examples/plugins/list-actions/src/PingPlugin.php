<?php

declare(strict_types=1);

namespace TemeExamples\ListActions;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Plugins\Plugin;

/**
 * The handbook's list-actions example: the job a row and bulk action on the
 * content lists queues, which receives the items the person could edit.
 */
final class PingPlugin implements Plugin, PluginHasJobs
{
    public function jobs(): array
    {
        return [
            'ping' => new class implements JobHandler
            {
                public function handle(JobContext $context): void
                {
                    $items = (array) ($context->payload()['items'] ?? []);

                    $context->progress(count($items), count($items), 'Pinged.');
                }
            },
        ];
    }

    public function register(Hooks $hooks): void
    {
        // Nothing to hook: the actions are declared in the manifest.
    }
}
