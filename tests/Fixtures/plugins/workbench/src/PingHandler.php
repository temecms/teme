<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Workbench;

use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Settings\PluginSettingsWriter;

/**
 * The workbench's one job: runs what the test set, with the plugin's
 * settings door.
 */
final readonly class PingHandler implements JobHandler
{
    public function __construct(private PluginSettingsWriter $settings) {}

    public function handle(JobContext $context): void
    {
        if (Workbench::$onPing !== null) {
            (Workbench::$onPing)($this->settings);
        }
    }
}
