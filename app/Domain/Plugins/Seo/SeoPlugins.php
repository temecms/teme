<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Seo;

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Columns\ColumnClock;
use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\ManifestText;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use Closure;
use Throwable;

/**
 * The enabled plugins the search sockets ask, in the order they were
 * enabled (docs/guides/developers/plugins/seo.md), and the one place their
 * faults are recorded. Read from the records and manifests each time;
 * holds nothing itself.
 */
final readonly class SeoPlugins
{
    public function __construct(
        private PluginRecords $records,
        private PluginRegistry $registry,
        private LoadedPlugins $loaded,
        private ListenerFaults $faults,
        private ColumnClock $clock,
    ) {}

    /**
     * Every enabled plugin whose manifest is found, oldest enabled first,
     * its label in the admin's language.
     *
     * @return list<SeoPlugin>
     */
    public function all(): array
    {
        $records = $this->records->enabled();
        usort($records, fn ($a, $b): int => [$a->enabled_at?->getTimestamp() ?? 0, $a->name] <=> [$b->enabled_at?->getTimestamp() ?? 0, $b->name]);
        $locale = app()->getLocale();
        $running = $this->loaded->running();
        $plugins = [];

        foreach ($records as $record) {
            $found = $this->registry->find($record->name);
            $manifest = $found instanceof FoundPlugin ? $found->manifest : null;

            if ($manifest === null) {
                continue;
            }

            $plugins[] = new SeoPlugin(
                name: $record->name,
                code: $record->code,
                label: ManifestText::for($record->code)->text($manifest->label(), $locale),
                manifest: $manifest,
                granted: array_values(array_filter((array) ($record->consent['capabilities'] ?? []), is_string(...))),
                running: $running[$record->name] ?? null,
                record: $record,
            );
        }

        return $plugins;
    }

    /**
     * The enabled plugin with this code, or null.
     */
    public function find(string $code): ?SeoPlugin
    {
        foreach ($this->all() as $plugin) {
            if ($plugin->code === $code) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * What the plugin answers within its time, or null: a failure, or an
     * answer that came too late, is recorded as its fault and dropped.
     * The answer is whatever the plugin returned, which its contract's
     * types don't promise, so the caller checks every part of it.
     *
     * @param  Closure(): mixed  $call
     */
    public function ask(SeoPlugin $plugin, string $socket, string $contract, int $budgetMs, Closure $call): mixed
    {
        $started = $this->clock->nanoseconds();

        try {
            $answer = $call();
        } catch (Throwable $failure) {
            $this->fault($plugin, $socket, $contract, $failure);

            return null;
        }

        $took = intdiv($this->clock->nanoseconds() - $started, 1_000_000);

        if ($took > $budgetMs) {
            $this->fault($plugin, $socket, $contract, new SocketTooSlow($plugin->name, $socket, $budgetMs, $took));

            return null;
        }

        return $answer;
    }

    /**
     * Records a plugin's failure under its name and the socket's, never
     * stopping what asked: a fault that can't be recorded is dropped.
     */
    public function fault(SeoPlugin $plugin, string $socket, string $contract, Throwable $failure): void
    {
        try {
            $this->faults->record($plugin->name, "{$plugin->name}#{$socket}", $contract, $failure);
        } catch (Throwable) {
            // A fault that can't be recorded never stops a page or the editor.
        }
    }
}
