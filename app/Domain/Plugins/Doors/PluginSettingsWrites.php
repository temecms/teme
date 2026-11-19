<?php

/**
 * A plugin's door to its own settings group: only for the person its work
 * runs for, never the operator, through WritePluginSettings
 * (docs/proposals/plugin-sockets-1.md).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Plugins\Operations\Input\WritePluginSettingsInput;
use App\Domain\Plugins\Operations\WritePluginSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Settings\PluginSettingsWriter;

final readonly class PluginSettingsWrites implements PluginSettingsWriter
{
    public function __construct(private string $code) {}

    public function write(array $values, string $actingPersonId): void
    {
        ActingPerson::require($actingPersonId);

        try {
            app(WritePluginSettings::class)->execute(new WritePluginSettingsInput($this->code, $values, $actingPersonId));
        } catch (AuthorizationException $refused) {
            throw new WriteRefused($refused->getMessage() !== '' ? $refused->getMessage() : __('Not permitted.'), previous: $refused);
        } catch (ValidationException $refused) {
            $first = collect($refused->errors())->flatten()->first();

            throw new WriteRefused(is_string($first) && $first !== '' ? $first : __('The change was refused.'), previous: $refused);
        }
    }
}
