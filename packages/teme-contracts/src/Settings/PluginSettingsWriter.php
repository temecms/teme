<?php

declare(strict_types=1);

namespace Teme\Contracts\Settings;

use Teme\Contracts\Content\WriteRefused;

/**
 * Writes the calling plugin's own settings group, `plugin.{code}`, for a
 * named person. Offered to a plugin that declares settings.
 *
 * @since 1.0.0
 */
interface PluginSettingsWriter
{
    /**
     * Saves these values, by declared key, as $actingPersonId could on the settings page.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws WriteRefused when the person, a key, a type or a value is refused; nothing is written
     *
     * @since 1.0.0
     */
    public function write(array $values, string $actingPersonId): void;
}
