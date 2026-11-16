<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Workbench;

use Closure;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Lists\HasPluginLists;
use Teme\Contracts\Lists\PluginList;
use Teme\Contracts\Plugins\Plugin;
use Teme\Contracts\Settings\PluginSettingsWriter;

/**
 * A fixture plugin that writes its own settings from its job, offers a
 * list on its settings page, and runs its job on a schedule. The tests set
 * what the job and the list do.
 */
final class Workbench implements HasPluginLists, Plugin, PluginHasJobs
{
    /** @var (Closure(PluginSettingsWriter): void)|null what the ping job does */
    public static ?Closure $onPing = null;

    /** @var (Closure(int): mixed)|null what the log list answers, given its limit */
    public static ?Closure $rows = null;

    /** @var array<string, PluginList>|null the lists it offers, when a test changes them */
    public static ?array $lists = null;

    /** @var list<int> the limits the log list was asked with */
    public static array $asked = [];

    public function __construct(public readonly PluginSettingsWriter $settings) {}

    public static function reset(): void
    {
        self::$onPing = null;
        self::$rows = null;
        self::$lists = null;
        self::$asked = [];
    }

    public function register(Hooks $hooks): void {}

    /**
     * @return array<string, JobHandler>
     */
    public function jobs(): array
    {
        return ['ping' => new PingHandler($this->settings)];
    }

    /**
     * @return array<string, PluginList>
     */
    public function pluginLists(): array
    {
        return self::$lists ?? ['log' => new LogList];
    }
}
