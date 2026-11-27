<?php

/**
 * Site › Plugins › Provider choices (docs/guides/developers/plugins/provider-slots.md):
 * the screen where an administrator picks which enabled plugin fills each
 * provider slot, or none. Each change goes through ChooseSlotProvider.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Plugins\Operations\ChooseSlotProvider;
use App\Domain\Plugins\Operations\Input\ChooseSlotProviderInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Slots\PluginSlots;
use App\Domain\Plugins\Slots\SlotProviders;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PluginSlotsController extends Controller
{
    /**
     * Every fillable slot, with the plugins that can fill it and the one chosen now.
     */
    public function index(SlotProviders $providers, PluginRegistry $registry): Response
    {
        $slots = [];

        foreach (PluginSlots::all() as $id => $slot) {
            if ($slot['contract'] === null) {
                continue;
            }

            $names = $providers->providers($id);

            $slots[] = [
                'id' => $id,
                'label' => __($slot['label']),
                'chosen' => $providers->chosenFor($id),
                'providers' => $names->map(fn (string $name): array => [
                    'name' => $name,
                    'label' => $registry->find($name)?->manifest?->label() ?? $name,
                ])->values()->all(),
            ];
        }

        return Inertia::render('plugins/slots', ['slots' => $slots]);
    }

    /**
     * Chooses the plugin for a slot, or none (an empty plugin), through ChooseSlotProvider.
     */
    public function choose(Request $request, string $slot, ChooseSlotProvider $choose): RedirectResponse
    {
        $plugin = $request->input('plugin');

        $choose->execute(new ChooseSlotProviderInput(
            slot: $slot,
            plugin: is_string($plugin) && $plugin !== '' ? $plugin : null,
            actorId: $request->user()?->getAuthIdentifier() === null ? null : (string) $request->user()->getAuthIdentifier(),
        ));

        return to_route('plugins.slots')->with('status', __('Saved.'));
    }
}
