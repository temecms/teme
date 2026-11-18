<?php

/**
 * Starts the enabled plugins at boot (I-3353): each one is built with only
 * what Teme offers plugins, given its own Hooks, and skipped, with what it
 * subscribed removed, when it fails to start.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins;

use App\Domain\Plugins\Faults\SpooledFaults;
use App\Domain\Plugins\Hooks\DeclaredHooks;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Seams\HookSubscriptions;
use App\Domain\Plugins\Seams\PluginContributions;
use App\Domain\Plugins\Seams\PluginServices;
use App\Domain\Plugins\Tables\PluginMigrations;
use App\Domain\PublicRoutes\RootFile;
use Composer\Autoload\ClassLoader;
use ReflectionClass;
use ReflectionNamedType;
use Teme\Contracts\Plugins\Plugin;
use Throwable;

final readonly class PluginLoader
{
    public function __construct(
        private PluginRegistry $registry,
        private PluginRecords $records,
        private SafeMode $safeMode,
        private ManifestValidator $validator,
        private LoadedPlugins $loaded,
        private HookSubscriptions $subscriptions,
        private PluginServices $services,
        private PluginContributions $contributions,
        private SpooledFaults $faults,
        private PluginMigrations $migrations,
        private Dependencies $dependencies,
    ) {}

    /**
     * Starts every enabled plugin that may run, once a process (I-3353): not on a
     * safe page, not one the server disables, and not one whose code, Teme
     * version or consent is wrong. A plugin that fails to start is skipped,
     * with what it subscribed removed, and the next one starts. No plugin
     * file is opened on a safe page, with every plugin disabled on the
     * server, with none installed, or with none enabled.
     */
    public function load(): void
    {
        // A fresh set of plugins' sentences each load, and none until a plugin with words starts (docs/plans/plugin-sockets-1.md, A-W14).
        app('translator')->handleMissingKeysUsing(null);
        app()->instance(PluginSentences::class, new PluginSentences(app(CodeScan::class)));

        // Before any plugin file is opened: a safe page, the server's switch for all, no plugin installed, or none enabled starts nothing.
        if ($this->safeMode->active() || $this->disabledOnServer() === true || ! $this->registry->anyInstalled()) {
            return;
        }

        try {
            $records = $this->records->enabled();
            $found = $records === [] ? [] : $this->registry->all();
        } catch (Throwable $failure) {
            // Looking for plugins must never lock anyone out: report it and start none.
            report($failure);

            return;
        }

        /** @var list<string> $disabledOnServer every plugin's switch returned above */
        $disabledOnServer = $this->disabledOnServer();

        // A plugin's own hooks' classes load before a plugin that listens to them.
        foreach ($this->dependencies->ordered($records) as $record) {
            $plugin = $found[$record->name] ?? null;
            $why = $this->skipReason($record, $plugin, $disabledOnServer);

            if ($why !== null || $plugin === null) {
                $this->loaded->skipped($record->name, $why);

                continue;
            }

            $this->start($record, $plugin, $found);
        }
    }

    /**
     * Why this enabled plugin may not load now, or null when it may: the
     * server's switch (one name or `*`), its code gone, a live error in its
     * manifest or code, an update that asks for more than was agreed, or
     * migrations waiting. Starting it and answering its public routes follow
     * this one rule.
     *
     * @param  true|list<string>|null  $disabledOnServer  the server's switch, read afresh when null
     *
     * @since 1.0.0
     */
    public function skipReason(PluginRecord $record, ?FoundPlugin $plugin, true|array|null $disabledOnServer = null): ?PluginState
    {
        $disabledOnServer ??= $this->disabledOnServer();

        return match (true) {
            $disabledOnServer === true || in_array($record->name, $disabledOnServer, true) => PluginState::DisabledOnServer,
            $plugin === null => PluginState::CodeGone,
            ! $plugin->usable() || $plugin->manifest === null || $this->validator->liveErrors($plugin) !== [] => PluginState::CantBeUsed,
            $this->dependencies->problems($plugin->manifest) !== [] || $this->dependencies->unmet($plugin->manifest) !== null => PluginState::CantBeUsed,
            ! Consent::covers($record->consent, $plugin->manifest) => PluginState::Waiting,
            $this->migrations->waiting($plugin, $record->migrations) !== [] => PluginState::Waiting,
            default => null,
        };
    }

    /**
     * The plugins the server's TEME_DISABLED_PLUGINS names, or true for `*`, every plugin.
     *
     * @return true|list<string>
     */
    public function disabledOnServer(): true|array
    {
        $names = array_values(array_filter(array_map(trim(...), explode(',', (string) config('teme.plugins.disabled'))), fn (string $name): bool => $name !== ''));

        return in_array('*', $names, true) ? true : $names;
    }

    /**
     * @param  array<string, FoundPlugin>  $found  every plugin found, by name
     */
    private function start(PluginRecord $record, FoundPlugin $plugin, array $found): void
    {
        /** @var Manifest $manifest */
        $manifest = $plugin->manifest;
        $quarantined = array_keys(array_filter($record->quarantine, fn (mixed $entry): bool => is_array($entry) && ($entry['version'] ?? null) === $manifest->version()));
        $hooks = $this->subscriptions->for($plugin->name, $record->consent['capabilities'] ?? [], $manifest->hooks(), $quarantined);

        try {
            $instance = $this->build($plugin, $record);
            $refusal = DeclaredHooks::of($manifest) === [] ? null : DeclaredHooks::refusal($manifest, $this->namespaces($plugin), $plugin->path, $this->othersNamespaces($plugin, $found));

            if ($refusal !== null) {
                throw new PluginRefused($refusal);
            }

            $instance->register($hooks);
            $jobs = $manifest->raw()['jobs'] ?? [];
            $problems = $this->contributions->add($plugin->name, $plugin->code, $manifest->label(), $manifest->version(), $manifest->contributes(), $instance, is_array($jobs) ? array_values($jobs) : [], rootFiles: RootFile::declaredIn($manifest) !== []);

            if ($problems !== []) {
                throw new PluginRefused($problems[0]);
            }
        } catch (Throwable $failure) {
            $this->subscriptions->forget($plugin->name);
            $this->faults->record($plugin->name, $plugin->name.'#boot', '', $failure);
            $this->loaded->failed($plugin->name, $failure->getMessage());

            return;
        } finally {
            $this->subscriptions->close($hooks);
        }

        $this->loaded->started($plugin->name, $instance);
        $this->words($plugin);
    }

    /**
     * The started plugin's own words, its lang/<locale>.json, which only
     * add sentences core and earlier plugins don't hold (docs/plans/plugin-sockets-1.md, A-W14).
     */
    private function words(FoundPlugin $plugin): void
    {
        app(PluginSentences::class)->add($plugin, app('translator'));
    }

    /**
     * Builds the plugin with its doors, without registering it — for a job
     * run that needs the same doors as boot, optionally with a longer
     * outbound ceiling and a larger answer (I-3445).
     *
     * @since 1.0.0
     */
    public function instance(FoundPlugin $plugin, PluginRecord $record, ?int $httpSeconds = null, ?int $answerBytes = null): Plugin
    {
        return $this->build($plugin, $record, httpSeconds: $httpSeconds, answerBytes: $answerBytes);
    }

    /**
     * The plugin, built with what Teme gives it for each type its
     * constructor asks for, never through the container.
     */
    private function build(FoundPlugin $plugin, PluginRecord $record, ?int $httpSeconds = null, ?int $answerBytes = null): Plugin
    {
        /** @var Manifest $manifest */
        $manifest = $plugin->manifest;
        $class = $manifest->pluginClass();
        $this->autoload($plugin, $class);

        $reflection = new ReflectionClass($class);
        $services = $this->services->for($plugin->name, $plugin->code, $record->consent['capabilities'] ?? [], Consent::hosts($record->consent, $manifest), httpSeconds: $httpSeconds, answerBytes: $answerBytes);
        $arguments = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;

            // The validator refuses such a plugin; this guards a cache from before.
            $arguments[] = $services[$name] ?? throw new PluginRefused("{$class} asks for {$name}; a plugin may ask only for what Teme offers plugins.");
        }

        $instance = $reflection->newInstanceArgs($arguments);

        if (! $instance instanceof Plugin) {
            throw new PluginRefused("{$class} doesn’t implement Teme\\Contracts\\Plugins\\Plugin.");
        }

        return $instance;
    }

    /**
     * The namespaces the plugin's own composer.json autoloads, each ending
     * in a backslash: where its own hooks' classes must be.
     *
     * @return list<string>
     */
    private function namespaces(FoundPlugin $plugin): array
    {
        $composer = json_decode((string) @file_get_contents($plugin->path.'/composer.json'), true);
        $composer = is_array($composer) ? $composer : (app(InstalledPackages::class)->entry($plugin->name) ?? []);
        $psr4 = is_array($composer['autoload']['psr-4'] ?? null) ? $composer['autoload']['psr-4'] : [];

        return array_values(array_filter(array_map(fn (int|string $prefix): string => (string) $prefix, array_keys($psr4)), fn (string $prefix): bool => str_ends_with($prefix, '\\')));
    }

    /**
     * The namespaces every other found plugin autoloads: a plugin's own
     * hooks may not be in any of them.
     *
     * @param  array<string, FoundPlugin>  $found
     *
     * @return list<string>
     */
    private function othersNamespaces(FoundPlugin $plugin, array $found): array
    {
        $namespaces = [];

        foreach ($found as $other) {
            if ($other->name !== $plugin->name) {
                $namespaces = [...$namespaces, ...$this->namespaces($other)];
            }
        }

        return $namespaces;
    }

    /**
     * A plugin in `teme.plugin_paths` isn't in Composer's autoloader: its
     * own composer.json's psr-4 folders are added, only now it starts.
     *
     * @param  class-string|string  $class
     */
    private function autoload(FoundPlugin $plugin, string $class): void
    {
        if (class_exists($class)) {
            return;
        }

        $composer = json_decode((string) @file_get_contents($plugin->path.'/composer.json'), true);
        $psr4 = is_array($composer['autoload']['psr-4'] ?? null) ? $composer['autoload']['psr-4'] : [];
        $loader = new ClassLoader;

        foreach ($psr4 as $prefix => $folders) {
            $loader->addPsr4((string) $prefix, array_map(fn (mixed $folder): string => $plugin->path.'/'.$folder, (array) $folders));
        }

        $loader->register();
    }
}
