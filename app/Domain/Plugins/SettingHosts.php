<?php

/**
 * The hosts a plugin reaches because a person entered them in its own url
 * or urls settings, the ones its manifest's `hostsFrom` names
 * (docs/plans/plugin-sockets-1.md, A2): only exact host names, and only
 * those a person agreed to, at enable or on a settings save, while a
 * setting still names them.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins;

use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsRegistry;
use Teme\Contracts\Settings\SettingsReader;

final readonly class SettingHosts
{
    /** The most settings `hostsFrom` may name. */
    public const int MOST_SETTINGS = 3;

    public function __construct(
        private PluginRegistry $registry,
        private PluginRecords $records,
        private SettingsRegistry $settings,
        private SettingsReader $reader,
    ) {}

    /**
     * Returns what the manifest takes hosts from, as it lists them: its
     * settings' keys, and its record url fields as "record.field".
     *
     * @return list<string>
     */
    public static function declared(Manifest $manifest): array
    {
        $keys = $manifest->raw()['hostsFrom'] ?? [];

        return is_array($keys) ? array_values(array_unique(array_filter($keys, is_string(...)))) : [];
    }

    /**
     * Returns the setting keys among what the manifest takes hosts from.
     *
     * @return list<string>
     */
    public static function settingKeys(Manifest $manifest): array
    {
        return array_values(array_filter(self::declared($manifest), fn (string $entry): bool => ! str_contains($entry, '.')));
    }

    /**
     * Returns the record url fields the manifest takes hosts from, as
     * "record.field" each, in its order.
     *
     * @return list<string>
     */
    public static function recordFields(Manifest $manifest): array
    {
        return array_values(array_filter(self::declared($manifest), fn (string $entry): bool => str_contains($entry, '.')));
    }

    /**
     * Returns the url field keys of one record the manifest takes hosts from.
     *
     * @return list<string>
     */
    public static function recordKeys(Manifest $manifest, string $record): array
    {
        $prefix = $record.'.';

        return array_values(array_map(fn (string $entry): string => substr($entry, strlen($prefix)), array_filter(self::recordFields($manifest), fn (string $entry): bool => str_starts_with($entry, $prefix))));
    }

    /**
     * Returns the url and urls settings the manifest declares, key => label.
     *
     * @return array<string, string>
     */
    public static function urlSettings(Manifest $manifest): array
    {
        $found = [];
        $settings = $manifest->contributes()['settings'] ?? [];

        foreach (is_array($settings) ? $settings : [] as $setting) {
            if (is_array($setting) && is_string($setting['key'] ?? null) && in_array($setting['type'] ?? null, ['url', 'urls'], true)) {
                $found[$setting['key']] = is_string($setting['label'] ?? null) ? $setting['label'] : $setting['key'];
            }
        }

        return $found;
    }

    /**
     * Returns the labels of the settings `hostsFrom` names, in its order, in
     * the current locale from the plugin's own lang folder.
     *
     * @return list<string>
     */
    public static function labels(Manifest $manifest): array
    {
        $labels = self::urlSettings($manifest);
        $words = ManifestText::for($manifest->code());
        $locale = app()->getLocale();

        return array_map(fn (string $key): string => isset($labels[$key]) ? $words->text($labels[$key], $locale) : $key, self::settingKeys($manifest));
    }

    /**
     * Returns each exact host name the values of these keys name, each once,
     * in order: a url setting's one address, a urls setting's each. Anything
     * that isn't an address with a host name, a wildcard or an IP literal
     * names none.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     *
     * @return list<string>
     */
    public static function hostsIn(array $values, array $keys): array
    {
        $hosts = [];

        foreach ($keys as $key) {
            $value = $values[$key] ?? null;

            foreach (is_array($value) ? $value : [$value] as $address) {
                $host = is_string($address) ? strtolower((string) parse_url(trim($address), PHP_URL_HOST)) : '';

                if ($host !== '' && ! str_starts_with($host, '*.') && preg_match(HostPattern::PATTERN, $host) === 1) {
                    $hosts[$host] = true;
                }
            }
        }

        return array_keys($hosts);
    }

    /**
     * Returns the hosts the plugin's `hostsFrom` settings name now, defaults included.
     *
     * @return list<string>
     */
    public function current(Manifest $manifest): array
    {
        $keys = self::settingKeys($manifest);

        return $keys === [] ? [] : self::hostsIn($this->values($manifest), $keys);
    }

    /**
     * Returns the hosts this enabled plugin may reach through its settings:
     * those its agreed `hostsFrom` settings name now that a person agreed
     * to. A host removed from the setting is reachable no more; put back,
     * it needs no new yes.
     *
     * @return list<string>
     */
    public function reachable(string $name): array
    {
        $manifest = $this->registry->find($name)?->manifest;

        if ($manifest === null) {
            return [];
        }

        $record = null;

        foreach ($this->records->enabled() as $enabled) {
            if ($enabled->name === $name) {
                $record = $enabled;
            }
        }

        if ($record === null) {
            return [];
        }

        // Hosts a person agreed to for the plugin's record url fields, kept until someone removes them;
        // only while the manifest still takes hosts from a record field that was agreed.
        $agreedFields = array_values(array_filter(Consent::hostsFrom($record->consent, $manifest), fn (string $entry): bool => str_contains($entry, '.')));
        $fromRecords = $agreedFields === [] ? [] : RecordHosts::agreed($record->consent);

        $agreed = array_values(array_filter((array) ($record->consent['settingHosts'] ?? []), is_string(...)));
        $keys = array_values(array_filter(Consent::hostsFrom($record->consent, $manifest), fn (string $entry): bool => ! str_contains($entry, '.')));

        if ($agreed === [] || $keys === []) {
            return $fromRecords;
        }

        return array_values(array_unique([...array_intersect(self::hostsIn($this->values($manifest), $keys), $agreed), ...$fromRecords]));
    }

    /**
     * Returns the plugin whose settings group this is, when its manifest
     * takes hosts from its settings: its name, label, the keys, and the
     * hosts agreed so far, in its settings and as its manifest's hosts;
     * null for any other group.
     *
     * @return array{name: string, label: string, keys: list<string>, agreed: list<string>, hosts: list<string>}|null
     */
    public function forGroup(string $group): ?array
    {
        if (! str_starts_with($group, 'plugin.')) {
            return null;
        }

        $code = substr($group, strlen('plugin.'));

        foreach ($this->registry->all() as $plugin) {
            if ($plugin->code !== $code || $plugin->manifest === null || self::settingKeys($plugin->manifest) === []) {
                continue;
            }

            $consent = [];

            foreach ($this->records->enabled() as $record) {
                if ($record->name === $plugin->name) {
                    $consent = $record->consent;
                }
            }

            return [
                'name' => $plugin->name,
                'label' => $plugin->manifest->label(),
                'keys' => self::settingKeys($plugin->manifest),
                'agreed' => array_values(array_filter((array) ($consent['settingHosts'] ?? []), is_string(...))),
                'hosts' => $consent === [] ? [] : Consent::hosts($consent, $plugin->manifest),
            ];
        }

        return null;
    }

    /**
     * The plugin's settings as they stand: read through the settings once
     * its group is registered (the plugin runs), otherwise its declared
     * defaults under what was saved, as before it is first enabled.
     *
     * @return array<string, mixed>
     */
    private function values(Manifest $manifest): array
    {
        $group = 'plugin.'.$manifest->code();

        if ($this->settings->find($group) !== null) {
            return $this->reader->group($group);
        }

        $values = [];
        $settings = $manifest->contributes()['settings'] ?? [];

        foreach (is_array($settings) ? $settings : [] as $setting) {
            if (is_array($setting) && is_string($setting['key'] ?? null)) {
                $values[$setting['key']] = $setting['default'] ?? null;
            }
        }

        $stored = SettingsGroupRecord::query()->find($group)?->overrides;

        return [...$values, ...(is_array($stored) ? $stored : [])];
    }
}
