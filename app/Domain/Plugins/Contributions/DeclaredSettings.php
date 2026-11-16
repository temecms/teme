<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Contributions;

use App\Domain\Cache\PageTags;
use App\Domain\Plugins\PluginAccess;
use InvalidArgumentException;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingType;

/**
 * Builds the settings group a plugin declares in its manifest's
 * `contributes.settings`, which Teme draws under Plugin settings for
 * holders of plugins.manage (I-3353).
 */
final class DeclaredSettings
{
    /**
     * Returns the plugin's settings group, `plugin.{code}`, built with the
     * contract's own constructors; sensitive when any setting is a secret.
     * A plugin that inserts content in articles purges the pages that asked
     * it when its settings are saved, and one with root files the stored
     * "not found" pages at root-file addresses. Throws InvalidArgumentException for a
     * setting Teme can't build.
     *
     * @param  list<mixed>  $settings  as the manifest declares them
     */
    public static function group(string $code, string $label, string $description, string $version, array $settings, bool $inserts = false, bool $rootFiles = false): SettingsGroup
    {
        $key = "plugin.{$code}";
        $built = [];

        foreach ($settings as $declared) {
            $built[] = self::setting($key, is_array($declared) ? $declared : [], $version);
        }

        return new SettingsGroup(
            key: $key,
            label: $label,
            description: $description,
            section: 'plugins',
            ability: PluginAccess::MANAGE,
            settings: $built,
            since: $version,
            sensitive: array_any($built, fn (Setting $setting): bool => $setting->type === SettingType::Secret),
            // Its settings may change what it inserts in articles (I-064).
            cacheTags: [
                ...($inserts ? [PageTags::INSERTIONS] : []),
                // Its settings may fill a root file's name (docs/guides/developers/plugins/root-files.md).
                ...($rootFiles ? [PageTags::ROOT_FILES] : []),
            ],
        );
    }

    /** @param  array<array-key, mixed>  $declared */
    private static function setting(string $group, array $declared, string $version): Setting
    {
        $key = self::text($declared, 'key');
        $type = self::text($declared, 'type');
        $label = self::text($declared, 'label');
        $description = self::text($declared, 'description');
        $options = is_array($declared['options'] ?? null) ? array_map(strval(...), $declared['options']) : [];

        if (preg_match(ContributionsValidator::KEY, $key) !== 1) {
            throw new InvalidArgumentException("Invalid setting key [{$group}.{$key}].");
        }

        $setting = match ($type) {
            'text' => Setting::text($key, $label, $description, $version),
            'textarea' => Setting::textarea($key, $label, $description, $version),
            'number' => Setting::number($key, $label, $description, $version),
            'boolean' => Setting::boolean($key, $label, $description, $version),
            'select' => Setting::select($key, $label, $description, $version, $options),
            'multiselect' => Setting::multiselect($key, $label, $description, $version, $options),
            'url' => Setting::url($key, $label, $description, $version),
            'urls' => Setting::urls($key, $label, $description, $version),
            'email' => Setting::email($key, $label, $description, $version),
            'secret' => Setting::secret($key, $label, $description, $version),
            'color' => Setting::color($key, $label, $description, $version),
            default => throw new InvalidArgumentException("Setting [{$group}.{$key}] has an unknown type [{$type}]."),
        };

        if (array_key_exists('default', $declared)) {
            $setting = $setting->default($declared['default']);
        }

        foreach (['min', 'max', 'maxLength'] as $bound) {
            if (is_int($declared[$bound] ?? null)) {
                $setting = $setting->{$bound}($declared[$bound]);
            }
        }

        if (($declared['required'] ?? false) === true) {
            $setting = $setting->required();
        }

        if (is_string($declared['example'] ?? null)) {
            $setting = $setting->example($declared['example']);
        }

        if (is_string($declared['unit'] ?? null)) {
            $setting = $setting->unit($declared['unit']);
        }

        return $setting;
    }

    /** @param  array<array-key, mixed>  $declared */
    private static function text(array $declared, string $name): string
    {
        return is_string($declared[$name] ?? null) ? $declared[$name] : '';
    }
}
