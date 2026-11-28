<?php

/**
 * A plugin's door to its free secrets (PluginSecretStore): reads its own
 * group's `store:` keys, and changes them only for the person its work runs
 * for, through ChangePluginSecret. Offered only to a plugin that declares a
 * secret setting.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Plugins\Operations\ChangePluginSecret;
use App\Domain\Plugins\Operations\Input\ChangePluginSecretInput;
use App\Domain\Settings\Models\SettingsGroupRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Settings\PluginSecretStore;

final readonly class PluginSecretStoreWrites implements PluginSecretStore
{
    public function __construct(private string $code) {}

    public function get(string $key): ?string
    {
        $value = SettingsGroupRecord::query()->whereKey("plugin.{$this->code}")->first()?->secrets[ChangePluginSecret::PREFIX.$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public function put(string $key, string $value, string $actingPersonId): void
    {
        $this->change($key, $value, $actingPersonId);
    }

    public function forget(string $key, string $actingPersonId): void
    {
        $this->change($key, null, $actingPersonId);
    }

    private function change(string $key, ?string $value, string $actingPersonId): void
    {
        ActingPerson::require($actingPersonId);

        try {
            app(ChangePluginSecret::class)->execute(new ChangePluginSecretInput($this->code, $key, $value, $actingPersonId));
        } catch (AuthorizationException $refused) {
            throw new WriteRefused($refused->getMessage() !== '' ? $refused->getMessage() : __('Not permitted.'), previous: $refused);
        } catch (ValidationException $refused) {
            $first = collect($refused->errors())->flatten()->first();

            throw new WriteRefused(is_string($first) && $first !== '' ? $first : __('The change was refused.'), previous: $refused);
        }
    }
}
