<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Scheduler;

use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\People\PeopleReader;
use Teme\Contracts\Plugins\Plugin;
use Teme\Contracts\Settings\SettingsReader;

/**
 * A fixture plugin like a post scheduler: it reads the queue for a person
 * chosen from the site, on a schedule its own settings switch and time.
 */
final class Scheduler implements Plugin, PluginHasJobs
{
    public function __construct(
        public readonly ContentReader $reader,
        public readonly PeopleReader $people,
        public readonly Jobs $jobs,
        public readonly SettingsReader $settings,
    ) {}

    public function register(Hooks $hooks): void {}

    /**
     * @return array<string, JobHandler>
     */
    public function jobs(): array
    {
        return [
            'publish-queue' => new PublishQueueHandler,
        ];
    }
}
