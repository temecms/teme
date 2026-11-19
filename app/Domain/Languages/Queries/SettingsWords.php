<?php

/**
 * A settings page's "In other languages" table (docs/proposals/
 * multilingual-screens.md, 6): the group's word settings, the default
 * language's words for each, and each other language's own, where it has
 * any. A theme's menu places are chosen on Navigation, not here. It reads
 * only; TranslateSettings writes.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages\Queries;

use App\Domain\Languages\SiteLanguages;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsTranslations;
use App\Domain\Settings\TranslatableSettings;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingType;

final class SettingsWords
{
    /**
     * The table's data, or null when the site has one language or the
     * group has no words.
     *
     * @return array{languages: list<array{tag: string, name: string}>, fields: list<array{key: string, label: string, multiline: bool}>, defaults: array<string, string>, values: array<string, array<string, string>>, version: int}|null
     */
    public static function for(string $group): ?array
    {
        $languages = app(SiteLanguages::class);
        $definition = app(SettingsRegistry::class)->find($group);

        if (! $languages->isMultilingual() || $definition === null || str_starts_with($group, ThemeSlotSettings::GROUP_PREFIX)) {
            return null;
        }

        $fields = array_values(array_map(
            fn (Setting $setting): array => ['key' => $setting->key, 'label' => $setting->label, 'multiline' => $setting->type === SettingType::Textarea],
            array_filter($definition->settings, fn (Setting $setting): bool => TranslatableSettings::has($group, $setting->key)),
        ));

        if ($fields === []) {
            return null;
        }

        $translations = app(SettingsTranslations::class);
        $defaults = [];
        $values = [];
        $version = 0;

        foreach ($languages->others() as $tag) {
            $words = $translations->of($group, $tag);
            $version = $words['version'];
            $defaults = array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $words['defaults']);
            $values[$tag] = array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $words['values']);
        }

        return [
            // compact(): the key is a language's tag, never the taxonomy (NoTypeLiteralsTest).
            'languages' => array_map(fn (string $tag): array => compact('tag') + ['name' => $languages->label($tag)], $languages->others()),
            'fields' => $fields,
            'defaults' => $defaults,
            'values' => $values,
            'version' => $version,
        ];
    }
}
