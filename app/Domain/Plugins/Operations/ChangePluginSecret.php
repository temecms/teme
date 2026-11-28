<?php

/**
 * Keeps or removes one of a plugin's free secrets (PluginSecretStore), for the
 * person its work runs for: that person must manage plugins and the plugin
 * must be enabled. A secret is kept in the plugin's own settings group, under
 * a `store:` key no declared secret can take, encrypted at rest, and the
 * change is recorded as through the plugin, never with the value.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Input\ChangePluginSecretInput;
use App\Domain\Plugins\PluginAccess;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class ChangePluginSecret extends BaseOperation
{
    /** The free secrets a plugin may keep. */
    public const int MOST_KEYS = 100;

    /** The longest value a free secret may hold, in bytes. */
    public const int MOST_BYTES = 4096;

    /** The prefix a free secret's key is stored under, which no declared secret can take. */
    public const string PREFIX = SettingsWriter::FREE_SECRET_PREFIX;

    /** A free key: a lower-case name starting with a letter. */
    public const string KEY = '/^[a-z][a-z0-9_]{1,63}$/D';

    public function __construct(
        private readonly Actors $actors,
        private readonly SettingsWriter $writer,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var ChangePluginSecretInput $input */
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
        /** @var ChangePluginSecretInput $input */
        $problems = [];

        if (preg_match(self::KEY, $input->key) !== 1) {
            $problems['key'] = __('A secret’s name is 2 to 64 lower-case letters, digits and underscores, starting with a letter.');
        }

        if ($input->value !== null && ($input->value === '' || strlen($input->value) > self::MOST_BYTES)) {
            $problems['value'] = __('A secret is text of 1 to 4 KB.');
        }

        if ($problems === [] && $input->value !== null) {
            $record = SettingsGroupRecord::query()->whereKey("plugin.{$input->code}")->first();
            $kept = $record === null ? [] : ($record->secrets ?? []);
            $free = count(array_filter(array_keys($kept), fn (string $name): bool => str_starts_with($name, self::PREFIX)));

            if (! array_key_exists(self::PREFIX.$input->key, $kept) && $free >= self::MOST_KEYS) {
                $problems['key'] = __('A plugin keeps at most 100 secrets.');
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    protected function perform(OperationInput $input): mixed
    {
        /** @var ChangePluginSecretInput $input */
        // The writer locks the group's row; a removal of a key not kept changes nothing.
        return $this->writer->write(new SettingsWrite(
            group: "plugin.{$input->code}",
            values: [],
            secrets: [self::PREFIX.$input->key => $input->value],
            actorId: $input->actorId,
            origin: "plugin:{$input->code}",
        ));
    }
}
