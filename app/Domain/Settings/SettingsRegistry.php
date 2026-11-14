<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use Closure;
use InvalidArgumentException;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsRegistrar;

/**
 * Every settings group and setting the site declares, checked as each is
 * registered. The reader, the settings form and the generated reference all
 * look groups up here.
 */
final class SettingsRegistry implements SettingsRegistrar
{
    private const string GROUP_KEY = '/^[a-z][a-z0-9_]*(\.[a-z0-9][a-z0-9_-]*)?$/';

    private const string SETTING_KEY = '/^[a-z][a-z0-9_]*$/';

    /** @var array<string, SettingsGroup|null> null holds a derived group's place in the order */
    private array $groups = [];

    /** @var array<string, string> each group's owner: core, or the plugin that added it (I-3353) */
    private array $owners = [];

    /** @var array<string, array{build: Closure(): SettingsGroup, stamp: Closure(): string, group: SettingsGroup|null, at: string|null}> */
    private array $derived = [];

    /**
     * Registers a group, recording who added it: core, or a plugin's
     * Composer name, held in memory and never stored (I-3353).
     */
    public function group(SettingsGroup $group, string $owner = 'core'): void
    {
        $this->check($group);

        if (array_key_exists($group->key, $this->groups)) {
            throw new InvalidArgumentException("Settings group [{$group->key}] is already registered.");
        }

        $this->groups[$group->key] = $group;
        $this->owners[$group->key] = $owner;
    }

    /**
     * Returns who added the group with this key, core or a plugin's
     * Composer name, or null when no group has it.
     */
    public function ownerOf(string $key): ?string
    {
        return $this->owners[$key] ?? null;
    }

    /**
     * Registers a group built from something that changes while the process
     * runs, such as the content types (I-3296): built when first read, and
     * again whenever $stamp answers differently, checked as group() checks a
     * group, and kept in its place in the order.
     *
     * @param  Closure(): SettingsGroup  $build
     * @param  Closure(): string  $stamp
     */
    public function derive(string $key, Closure $build, Closure $stamp): void
    {
        if (array_key_exists($key, $this->groups)) {
            throw new InvalidArgumentException("Settings group [{$key}] is already registered.");
        }

        $this->groups[$key] = null;
        $this->owners[$key] = 'core';
        $this->derived[$key] = ['build' => $build, 'stamp' => $stamp, 'group' => null, 'at' => null];
    }

    /**
     * Registers a group that is built from something that changes after
     * boot, such as the installed themes: checked as group() checks it, and
     * put in the place of any group with its key, which keeps that place in
     * the order. A derived group replaced this way stays as given. group()
     * still refuses a duplicate, which guards core's own.
     */
    public function replace(SettingsGroup $group): void
    {
        $this->check($group);

        unset($this->derived[$group->key]);
        $this->groups[$group->key] = $group;
        $this->owners[$group->key] ??= 'core';
    }

    private function check(SettingsGroup $group): void
    {
        if (! preg_match(self::GROUP_KEY, $group->key)) {
            throw new InvalidArgumentException("Invalid settings group key [{$group->key}].");
        }

        if (trim($group->description) === '') {
            throw new InvalidArgumentException("Settings group [{$group->key}] must describe itself.");
        }

        $seen = [];

        foreach ($group->settings as $setting) {
            $name = "{$group->key}.{$setting->key}";

            if (! preg_match(self::SETTING_KEY, $setting->key)) {
                throw new InvalidArgumentException("Invalid setting key [{$name}].");
            }

            if (isset($seen[$setting->key])) {
                throw new InvalidArgumentException("Setting [{$name}] is declared twice.");
            }

            // The reference documentation is generated from these fields
            // (docs/architecture/documentation.md), so they are required.
            if (trim($setting->label) === '' || trim($setting->description) === '' || trim($setting->since) === '') {
                throw new InvalidArgumentException("Setting [{$name}] must document itself: label, description and since.");
            }

            $seen[$setting->key] = true;
        }
    }

    public function find(string $key): ?SettingsGroup
    {
        if (! array_key_exists($key, $this->groups)) {
            return null;
        }

        return $this->groups[$key] ?? $this->built($key);
    }

    /**
     * @return list<SettingsGroup>
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->groups as $key => $group) {
            $all[] = $group ?? $this->built($key);
        }

        return $all;
    }

    /**
     * A derived group as its stamp says it is now: the one built last, or
     * built again.
     */
    private function built(string $key): SettingsGroup
    {
        $derived = $this->derived[$key];
        $stamp = ($derived['stamp'])();

        if ($derived['group'] !== null && $derived['at'] === $stamp) {
            return $derived['group'];
        }

        $group = ($derived['build'])();

        if ($group->key !== $key) {
            throw new InvalidArgumentException("Settings group [{$key}] was built as [{$group->key}].");
        }

        $this->check($group);
        $this->derived[$key]['group'] = $group;
        $this->derived[$key]['at'] = $stamp;

        return $group;
    }
}
