<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Contributions;

use App\Domain\Cache\PageTags;
use App\Domain\Plugins\ManifestText;
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
     * A plugin that inserts content in articles, or on every page, purges
     * the pages that asked it when its settings are saved, one with root files the stored
     * "not found" pages at root-file addresses, and one that adds links to
     * the feeds every stored feed, and one that adds head tags, structured
     * data or sitemaps every page that asked it. Throws InvalidArgumentException for a
     * setting Teme can't build.
     *
     * @param  list<mixed>  $settings  as the manifest declares them
     */
    public static function group(string $code, string $label, string $description, string $version, array $settings, bool $inserts = false, bool $insertsOnPages = false, bool $rootFiles = false, bool $feedLinks = false, bool $searchEngines = false): SettingsGroup
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
                ...($insertsOnPages ? [PageTags::PAGE_INSERTIONS] : []),
                // Its settings may fill a root file's name (docs/guides/developers/plugins/root-files.md).
                ...($rootFiles ? [PageTags::ROOT_FILES] : []),
                // Its settings may name the links it adds to the feeds (docs/plans/plugin-sockets-1.md, A3).
                ...($feedLinks ? [PageTags::FEED_LINKS] : []),
                // Its settings may change its head tags, structured data and sitemaps (docs/guides/developers/plugins/seo.md).
                ...($searchEngines ? ['plugin:'.$code] : []),
            ],
        );
    }

    /**
     * Returns a plugin group's settings form with the words its manifest
     * gave in the locale, from the plugin's own lang folder: the group's
     * label and description, and each setting's label, description, option
     * labels and unit. Values and examples stay as they are.
     *
     * @template T of array{group: array{key: string, label: string, description: string, sensitive: bool}, fields: list<array<string, mixed>>}
     *
     * @param  T  $form
     *
     * @return T
     */
    public static function translated(array $form, string $locale): array
    {
        $words = ManifestText::for(substr($form['group']['key'], strlen('plugin.')));
        $text = fn (mixed $english): mixed => is_string($english) ? $words->text($english, $locale) : $english;

        $form['group']['label'] = $words->text($form['group']['label'], $locale);
        $form['group']['description'] = $words->text($form['group']['description'], $locale);

        foreach ($form['fields'] as $index => $field) {
            foreach (['label', 'description', 'unit'] as $name) {
                $field[$name] = $text($field[$name] ?? null);
            }

            if (is_array($field['options'] ?? null)) {
                $field['options'] = array_map($text, $field['options']);
            }

            $form['fields'][$index] = $field;
        }

        return $form;
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
            'person' => Setting::person($key, $label, $description, $version),
            'term' => Setting::term($key, $label, $description, $version, self::text($declared, 'taxonomy'), ($declared['multiple'] ?? false) === true),
            'type' => Setting::contentType($key, $label, $description, $version),
            'code' => Setting::code($key, $label, $description, $version),
            // Its fields one level down, built by the same rules (docs/plans/plugin-sockets-2.md, A5).
            'repeater' => Setting::repeater($key, $label, $description, $version, array_map(
                fn (mixed $field): Setting => self::setting("{$group}.{$key}", is_array($field) ? $field : [], $version),
                is_array($declared['fields'] ?? null) ? array_values($declared['fields']) : [],
            )),
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

        // A repeater's item counts are its min and max.
        if ($type === 'repeater') {
            $setting = $setting->min(is_int($declared['minItems'] ?? null) ? $declared['minItems'] : 0)
                ->max(is_int($declared['maxItems'] ?? null) ? $declared['maxItems'] : 20);

            if (is_string($declared['itemTitle'] ?? null)) {
                $setting = $setting->itemTitle($declared['itemTitle']);
            }
        }

        if ($type === 'code' && ($declared['scripts'] ?? false) === true) {
            $setting = $setting->scripts();
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

        // Its condition and its section on the plugin's settings page (docs/plans/plugin-sockets-2.md, A4).
        if (is_array($declared['visibleWhen'] ?? null) && $declared['visibleWhen'] !== []) {
            /** @var array<string, scalar|list<scalar>> $conditions */
            $conditions = $declared['visibleWhen'];
            $setting = $setting->visibleWhen($conditions);
        }

        if (is_string($declared['section'] ?? null)) {
            $setting = $setting->section($declared['section']);
        }

        return $setting;
    }

    /** @param  array<array-key, mixed>  $declared */
    private static function text(array $declared, string $name): string
    {
        return is_string($declared[$name] ?? null) ? $declared[$name] : '';
    }
}
