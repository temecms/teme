<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Workbench;

use Closure;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Plugins\Plugin;
use Teme\Contracts\Settings\PluginSettingsWriter;

/**
 * A fixture plugin that writes its own settings from its job, which runs
 * on a schedule. The tests set what the job does.
 */
final class Workbench implements Plugin, PluginHasJobs
{
    /** @var (Closure(PluginSettingsWriter): void)|null what the ping job does */
    public static ?Closure $onPing = null;

    public function __construct(public readonly PluginSettingsWriter $settings) {}

    public static function reset(): void
    {
        self::$onPing = null;
    }

    public function register(Hooks $hooks): void {}

    /**
     * @return array<string, JobHandler>
     */
    public function jobs(): array
    {
        return ['ping' => new PingHandler($this->settings)];
    }
}
