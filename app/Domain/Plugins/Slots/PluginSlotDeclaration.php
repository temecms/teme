<?php

/**
 * What a plugin's manifest declares in `provides`: the provider slots it
 * fills (docs/guides/developers/plugins/provider-slots.md). Checked when the
 * manifest is read, and again when the plugin starts, where it must implement
 * each slot's contract.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Slots;

use Teme\Contracts\Plugins\Capability;

final class PluginSlotDeclaration
{
    /**
     * Why the declared slots can't be used, or nothing when they can.
     *
     * @param  list<string>|null  $capabilities  what the manifest asks for, when known
     *
     * @return list<string>
     */
    public static function errors(mixed $provides, ?array $capabilities = null): array
    {
        if (! is_array($provides) || ! array_is_list($provides)) {
            return [__('Its provides must be a list of slot names.')];
        }

        if (count($provides) > PluginSlots::MAX_PER_PLUGIN) {
            return [__('It may fill at most :count slots.', ['count' => PluginSlots::MAX_PER_PLUGIN])];
        }

        $errors = [];

        foreach ($provides as $slot) {
            if (! is_string($slot) || ! PluginSlots::exists($slot)) {
                $errors[] = __('Teme has no provider slot named :slot.', ['slot' => is_string($slot) ? $slot : '']);
            } elseif (! PluginSlots::fillable($slot)) {
                $errors[] = __('Teme doesn’t offer the :slot slot to plugins yet.', ['slot' => $slot]);
            }
        }

        if (count($provides) !== count(array_unique($provides))) {
            $errors[] = __('Each slot may be named once in provides.');
        }

        // The engine is handed every search the visitors make, so the plugin must say so in its capabilities.
        if ($capabilities !== null && in_array(PluginSlots::SEARCH, $provides, true) && ! in_array(Capability::SearchRead->value, $capabilities, true)) {
            $errors[] = __('A plugin that fills the search slot must ask for search.read.');
        }

        return $errors;
    }

    /**
     * What a starting plugin's class must implement for the slots it declares.
     *
     * @param  array<array-key, mixed>  $contributes
     *
     * @return list<string>
     */
    public static function startErrors(array $contributes, object $plugin): array
    {
        $errors = [];

        foreach ((array) ($contributes['provides'] ?? []) as $slot) {
            if (! is_string($slot) || ! PluginSlots::fillable($slot)) {
                continue;
            }

            $contract = PluginSlots::all()[$slot]['contract'];

            if ($contract === null) {
                continue;
            }

            if (! $plugin instanceof $contract) {
                $errors[] = __('It fills the :slot slot but doesn’t implement :interface.', ['slot' => $slot, 'interface' => $contract]);
            }

            if ($slot === PluginSlots::IMAGE_SERVICE && ! array_key_exists('imageService', $contributes)) {
                $errors[] = __('It fills the image service slot but declares no imageService.');
            }
        }

        return $errors;
    }
}
