<?php

/**
 * Saves a plugin's own settings for the person its work runs for
 * (docs/proposals/plugin-sockets-1.md): that person must manage plugins and
 * the plugin must be enabled. Only declared, non-secret, non-address values
 * are written, by the group's own rules and checks, recorded as through the
 * plugin. No elevation: the person isn't present.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Input\WritePluginSettingsInput;
use App\Domain\Plugins\PluginAccess;
use App\Domain\Settings\SettingRules;
use App\Domain\Settings\SettingsCheck;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingType;

final class WritePluginSettings extends BaseOperation
{
    /** The setting types a plugin may write for itself. */
    public const array WRITABLE = [
        SettingType::Text,
        SettingType::Textarea,
        SettingType::Number,
        SettingType::Boolean,
        SettingType::Select,
        SettingType::MultiSelect,
        SettingType::Email,
        SettingType::Color,
    ];

    public function __construct(
        private readonly Actors $actors,
        private readonly SettingsRegistry $registry,
        private readonly SettingsWriter $writer,
        private readonly Container $container,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var WritePluginSettingsInput $input */
        $person = $this->actors->find($input->actorId);
        $enabled = PluginRecord::query()
            ->where('code', $input->code)
            ->where('state', PluginRecord::ENABLED)
            ->exists();

        if ($person === null || ! $person->can(PluginAccess::MANAGE) || ! $enabled) {
            throw new AuthorizationException(__('Not permitted to change these settings.'));
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var WritePluginSettingsInput $input */
        $group = $this->group($input->code);
        $problems = [];

        foreach (array_keys($input->values) as $key) {
            $setting = $group->setting((string) $key);

            // A repeater only when none of its fields is code: a plugin never writes code that asks for scripts (docs/plans/plugin-sockets-2.md, A5).
            $repeater = $setting?->type === SettingType::Repeater && ! array_any($setting->fields, fn (Setting $field): bool => $field->type === SettingType::Code);

            if ($setting !== null && ! $repeater && ! in_array($setting->type, self::WRITABLE, true)) {
                $problems["values.{$key}"] = $setting->type === SettingType::Person
                    ? __('A plugin can’t choose a person for itself.')
                    : __('A plugin can’t write its own :type settings.', ['type' => $setting->type->value]);
            }
        }

        // The checks a part adds to this group, as UpdateSettings asks them.
        foreach ($this->container->tagged(SettingsCheck::TAG) as $check) {
            if ($check instanceof SettingsCheck && $check->group() === $group->key) {
                $problems += $check->problems($input->values);
            }
        }

        SettingRules::validateValues($group, $input->values, $problems);
    }

    protected function perform(OperationInput $input): int
    {
        /** @var WritePluginSettingsInput $input */
        // The writer locks the group's row and reads its version under the
        // lock; a plugin has no form opened on an older version to compare.
        return $this->writer->write(new SettingsWrite(
            group: $this->group($input->code)->key,
            values: $input->values,
            actorId: $input->actorId,
            origin: "plugin:{$input->code}",
        ));
    }

    private function group(string $code): SettingsGroup
    {
        return $this->registry->find("plugin.{$code}")
            ?? throw ValidationException::withMessages(['values' => __('This setting does not exist.')]);
    }
}
