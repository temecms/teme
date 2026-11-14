<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

use Teme\Contracts\Plugins\Plugin;

/**
 * What happened to each enabled plugin as this process booted (I-3353): the
 * ones running, and why each other one isn't. Filled once, at boot.
 */
final class LoadedPlugins
{
    /** @var array<string, Plugin> */
    private array $running = [];

    /** @var array<string, PluginState> */
    private array $skipped = [];

    /** @var array<string, string> */
    private array $failed = [];

    /**
     * A plugin that started.
     */
    public function started(string $name, Plugin $plugin): void
    {
        $this->running[$name] = $plugin;
    }

    /**
     * An enabled plugin that wasn't started, and why.
     */
    public function skipped(string $name, PluginState $why): void
    {
        $this->skipped[$name] = $why;
    }

    /**
     * An enabled plugin that failed to start, with the failure's message.
     */
    public function failed(string $name, string $error): void
    {
        $this->failed[$name] = $error;
    }

    /**
     * The plugins running in this process, by name, in the order they started.
     *
     * @return array<string, Plugin>
     */
    public function running(): array
    {
        return $this->running;
    }

    /**
     * Where this enabled plugin stands in this process: Enabled when it runs,
     * CantBeUsed when it failed to start, the reason it was skipped, or null
     * when the loader never came to it.
     */
    public function state(string $name): ?PluginState
    {
        return match (true) {
            isset($this->running[$name]) => PluginState::Enabled,
            isset($this->failed[$name]) => PluginState::CantBeUsed,
            default => $this->skipped[$name] ?? null,
        };
    }

    /**
     * Why this plugin failed to start, or null if it didn't.
     */
    public function failure(string $name): ?string
    {
        return $this->failed[$name] ?? null;
    }
}
