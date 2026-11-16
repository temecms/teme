<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Settings\Models\SettingsGroupRecord;
use InvalidArgumentException;
use Teme\Contracts\Settings\SettingType;

/**
 * A settings group's words in one other language, for the screens that
 * write them (I-3360): the words saved for it, the default language's
 * beside them, and the version a save is checked against. Reads only.
 */
final readonly class SettingsTranslations
{
    public function __construct(private SettingsRegistry $registry) {}

    /**
     * The group's words in this language, keyed by setting; the default
     * language's values of the same settings; and the group's version.
     *
     * @return array{values: array<string, mixed>, defaults: array<string, mixed>, version: int}
     */
    public function of(string $group, string $language): array
    {
        $definition = $this->registry->find($group) ?? throw new InvalidArgumentException("Unknown settings group [{$group}].");
        $record = SettingsGroupRecord::query()->find($group);
        $overrides = $record->overrides ?? [];
        $saved = $record->translations[$language] ?? [];
        $values = [];
        $defaults = [];

        foreach ($definition->settings as $setting) {
            if ($setting->type === SettingType::Secret || ! TranslatableSettings::has($group, $setting->key)) {
                continue;
            }

            $defaults[$setting->key] = array_key_exists($setting->key, $overrides) ? $overrides[$setting->key] : $setting->default;

            if (array_key_exists($setting->key, $saved)) {
                $values[$setting->key] = $saved[$setting->key];
            }
        }

        return ['values' => $values, 'defaults' => $defaults, 'version' => (int) ($record->version ?? 0)];
    }
}
