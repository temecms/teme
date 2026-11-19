<?php

/**
 * Saves changes on Settings › Design as a draft, which no reader sees until
 * PublishDesign. The draft holds only the keys changed, each stored as
 * publishing would store it; a value put back to the live one leaves the
 * draft. A slot's menu must exist, and an accent must pass contrast against
 * the light background of the theme that would be active (I-457).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Design\Operations;

use App\Domain\Design\Contrast;
use App\Domain\Design\Operations\Concerns\ManagesDesign;
use App\Domain\Design\Operations\Input\SaveDesignDraftInput;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingRules;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Themes\ThemeRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingType;

final class SaveDesignDraft extends BaseOperation
{
    use ManagesDesign;

    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly ThemeRegistry $themes,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var SaveDesignDraftInput $input */
        $this->authorizeDesign($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SaveDesignDraftInput $input */
        $problems = [];

        foreach ($input->groups as $group => $values) {
            $definition = $this->isDesignGroup((string) $group) ? $this->registry->find((string) $group) : null;

            if ($definition === null || ! is_array($values)) {
                $problems["groups.{$group}"] = [__('These settings aren\'t part of the design.')];

                continue;
            }

            $rules = [];
            $labels = [];

            foreach (array_keys($values) as $key) {
                $setting = $definition->setting((string) $key);

                if ($setting === null || $setting->type === SettingType::Secret) {
                    $problems["groups.{$group}.{$key}"] = [__('This setting does not exist.')];

                    continue;
                }

                $rules[$key] = SettingRules::for($setting);
                $labels[$key] = $setting->label;
            }

            // One group at a time, keyed by setting key: a group key such as
            // theme.good has a dot, which the validator would read as nesting.
            $validator = Validator::make($values, $rules, attributes: $labels);

            foreach ($validator->errors()->messages() as $key => $messages) {
                $problems["groups.{$group}.{$key}"] = $messages;
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    /**
     * @return array<string, array<string, mixed>> each group saved => its draft after the save, [] when nothing is pending
     */
    protected function perform(OperationInput $input): array
    {
        /** @var SaveDesignDraftInput $input */
        $records = [];
        $values = [];

        foreach ($input->groups as $group => $changes) {
            $group = (string) $group;
            SettingsGroupRecord::query()->insertOrIgnore(['group_key' => $group, 'overrides' => '{}', 'version' => 0]);
            $records[$group] = SettingsGroupRecord::query()->lockForUpdate()->findOrFail($group);

            foreach ((array) $changes as $key => $raw) {
                $values[$group][(string) $key] = SettingRules::normalise($this->setting($group, (string) $key), $raw);
            }
        }

        $this->checkMenus($values);
        $this->checkAccent($values, $records);

        $drafts = [];

        foreach ($values as $group => $changes) {
            $record = $records[$group];
            $draft = $record->draft_values ?? [];

            foreach ($changes as $key => $value) {
                if ($value === $this->live($record, $this->setting($group, $key))) {
                    unset($draft[$key]);
                } else {
                    $draft[$key] = $value;
                }
            }

            $drafts[$group] = $draft;

            if ($draft === []) {
                $this->clearDraft($record);

                continue;
            }

            $record->draft_values = $draft;
            $record->draft_updated_by = (int) $input->actorId;
            $record->draft_updated_at = now();
            // The live columns describe the live values: `updated_at` among
            // them, and `version`, which the generic form's stale check reads.
            $record->timestamps = false;
            $record->save();
        }

        return $drafts;
    }

    /**
     * A slot's menu must be none or a menu that exists; locked, so one being
     * deleted now can't be drafted as it goes. Slot settings are text, so
     * their type rule alone takes any string.
     *
     * @param  array<string, array<string, mixed>>  $values
     */
    private function checkMenus(array $values): void
    {
        $problems = [];

        foreach ($values as $group => $changes) {
            foreach ($changes as $key => $value) {
                if (str_starts_with($key, ThemeSlotSettings::SETTING_PREFIX) && $value !== '' && Navigation::query()->lockForUpdate()->find($value) === null) {
                    $problems["groups.{$group}.{$key}"] = __('There is no such menu.');
                }
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    /**
     * When this save changes the accent or the theme, the accent that would
     * be live must pass contrast against the light background of the theme
     * that would be active, so a theme switch can't slip past an accent the
     * new theme fails; the dark scheme takes a lighter version of it (I-457).
     * Each is this save's, else the draft's, else the live one.
     *
     * @param  array<string, array<string, mixed>>  $values
     * @param  array<string, SettingsGroupRecord>  $records
     */
    private function checkAccent(array $values, array $records): void
    {
        $changes = $values[self::DESIGN_GROUP] ?? [];

        if (! array_key_exists('accent', $changes) && ! array_key_exists('theme', $changes)) {
            return;
        }

        $record = $records[self::DESIGN_GROUP];
        $wouldBe = fn (string $key): string => (string) ($changes[$key] ?? $record->draft_values[$key] ?? $this->live($record, $this->setting(self::DESIGN_GROUP, $key)));

        $accent = $wouldBe('accent');

        if ($accent === '') {
            return;
        }

        $theme = $this->themes->find($wouldBe('theme'));
        $problems = Contrast::accentProblems($accent, Contrast::bases($theme !== null && $theme->usable() ? $theme->manifest : null)['light']);

        if ($problems !== []) {
            $field = array_key_exists('accent', $changes) ? 'accent' : 'theme';

            throw ValidationException::withMessages(['groups.'.self::DESIGN_GROUP.".{$field}" => implode(' ', $problems)]);
        }
    }

    private function setting(string $group, string $key): Setting
    {
        // validate() has checked both exist.
        return $this->registry->find($group)?->setting($key) ?? throw new LogicException("No setting [{$key}] in group [{$group}].");
    }

    /**
     * The value a setting has live: its stored override, else its default.
     */
    private function live(SettingsGroupRecord $record, Setting $setting): mixed
    {
        return array_key_exists($setting->key, $record->overrides) ? $record->overrides[$setting->key] : $setting->default;
    }
}
