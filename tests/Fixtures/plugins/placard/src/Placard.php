<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Placard;

use Teme\Contracts\Admin\HasPluginArea;
use Teme\Contracts\Admin\PluginArea;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin with an area of its own: ads as records in its own
 * table, their statistics as figures and a chart. The tests set what its
 * area does through PlacardArea's hooks.
 */
final class Placard implements HasPluginArea, Plugin, PluginHasJobs
{
    public function register(Hooks $hooks): void {}

    public function pluginArea(): PluginArea
    {
        return new PlacardArea;
    }

    /**
     * @return array<string, JobHandler>
     */
    public function jobs(): array
    {
        return [
            'prune' => new PruneHandler,
            'check-code' => new PruneHandler,
            'reset-stats' => new PruneHandler,
        ];
    }
}
