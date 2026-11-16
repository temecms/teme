<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

use App\Domain\Plugins\Hooks\DeclaredHooks;
use App\Domain\Plugins\Models\PluginRecord;
use Composer\Semver\Semver;
use UnexpectedValueException;

/**
 * Plugins that need other plugins (docs/plans/plugin-sockets-3.md):
 * what a manifest's `requires.plugins` names, whether each is enabled at a
 * version it accepts, which enabled plugins need a given one, and whether a
 * plugin listens only to hooks of plugins it requires. It reads manifests
 * and rows; it never runs a plugin's code.
 */
final readonly class Dependencies
{
    /** At most this many plugins a plugin requires. */
    public const int MOST = 5;

    public function __construct(
        private PluginRegistry $registry,
        private PluginRecords $records,
    ) {}

    /**
     * The plugins this manifest requires, by Composer name, each with its
     * version constraint; none when the list's shape is wrong.
     *
     * @return array<string, string>
     */
    public static function required(Manifest $manifest): array
    {
        $requires = $manifest->raw()['requires'] ?? null;
        $plugins = is_array($requires) ? ($requires['plugins'] ?? null) : null;

        if (! is_array($plugins) || ($plugins !== [] && array_is_list($plugins))) {
            return [];
        }

        $required = [];

        foreach ($plugins as $name => $constraint) {
            if (is_string($constraint)) {
                $required[(string) $name] = $constraint;
            }
        }

        return $required;
    }

    /**
     * Why this manifest can't be used beside the plugins found: it listens
     * to another plugin's hook without requiring that plugin, or to a hook
     * that plugin doesn't declare. Empty when it can.
     *
     * @return list<string>
     */
    public function problems(Manifest $manifest): array
    {
        $required = self::required($manifest);
        $problems = [];

        foreach ($manifest->hooks() as $hook) {
            if (preg_match(DeclaredHooks::FULL_NAME, $hook, $parts) !== 1) {
                continue;
            }

            $declaring = $this->byCode($parts[1]);
            $plugin = $declaring === null ? $parts[1] : $declaring->name;

            if ($declaring === null || ! array_key_exists($declaring->name, $required)) {
                $problems[] = __('It listens to :hook, but doesn’t list :plugin under requires.plugins.', ['hook' => $hook, 'plugin' => $plugin]);

                continue;
            }

            $names = $declaring->manifest === null ? [] : array_column(DeclaredHooks::of($declaring->manifest), 'name');

            if (! in_array($parts[2], $names, true)) {
                $problems[] = __('It listens to :hook, which :plugin doesn’t declare.', ['hook' => $hook, 'plugin' => $plugin]);
            }
        }

        return $problems;
    }

    /**
     * Why the plugins this manifest requires aren't there as it needs them,
     * or null when each is enabled at a version it accepts. $fresh reads the
     * rows from the table, as an Operation must; otherwise from the cache.
     */
    public function unmet(Manifest $manifest, bool $fresh = false): ?string
    {
        $required = self::required($manifest);

        if ($required === []) {
            return null;
        }

        $enabled = $this->enabledNames($fresh);

        foreach ($required as $name => $constraint) {
            $found = $this->registry->find($name)?->manifest;
            $words = ['plugin' => $name, 'constraint' => $constraint];

            if ($found === null || ! in_array($name, $enabled, true)) {
                return __('It needs :plugin :constraint enabled first.', $words);
            }

            if (! self::satisfies($found->version(), $constraint)) {
                return __('It needs :plugin :constraint; :version is installed.', [...$words, 'version' => $found->version()]);
            }
        }

        return null;
    }

    /**
     * The enabled plugins whose installed manifest requires this one, by
     * Composer name, read from the table: what refuses disabling it.
     *
     * @return list<string>
     */
    public function enabledDependents(string $name): array
    {
        return array_values(array_intersect($this->requiredBy($name), $this->enabledNames(true)));
    }

    /**
     * Every plugin found whose manifest requires this one, by Composer name, in name order.
     *
     * @return list<string>
     */
    public function requiredBy(string $name): array
    {
        $dependents = [];

        foreach ($this->registry->all() as $plugin) {
            if ($plugin->manifest !== null && $plugin->name !== $name && array_key_exists($name, self::required($plugin->manifest))) {
                $dependents[] = $plugin->name;
            }
        }

        sort($dependents);

        return array_values(array_unique($dependents));
    }

    /**
     * These rows, each after the rows of the plugins it requires, so a
     * plugin's own hooks' classes load before a plugin that listens to them.
     * Otherwise in the order given.
     *
     * @param  list<PluginRecord>  $records
     *
     * @return list<PluginRecord>
     */
    public function ordered(array $records): array
    {
        $byName = [];

        foreach ($records as $record) {
            $byName[$record->name] = $record;
        }

        $ordered = [];
        $visiting = [];

        $visit = function (string $name) use (&$visit, &$ordered, &$visiting, $byName): void {
            if (isset($ordered[$name]) || isset($visiting[$name]) || ! isset($byName[$name])) {
                return;
            }

            $visiting[$name] = true;
            $manifest = $this->registry->find($name)?->manifest;

            foreach (array_keys($manifest === null ? [] : self::required($manifest)) as $required) {
                $visit($required);
            }

            $ordered[$name] = $byName[$name];
        };

        foreach (array_keys($byName) as $name) {
            $visit($name);
        }

        return array_values($ordered);
    }

    private function byCode(string $code): ?FoundPlugin
    {
        foreach ($this->registry->all() as $plugin) {
            if ($plugin->code === $code) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function enabledNames(bool $fresh): array
    {
        if ($fresh) {
            /** @var list<string> */
            return PluginRecord::query()->where('state', PluginRecord::ENABLED)->orderBy('name')->pluck('name')->all();
        }

        return array_map(fn (PluginRecord $record): string => $record->name, $this->records->enabled());
    }

    private static function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (UnexpectedValueException) {
            return false;
        }
    }
}
