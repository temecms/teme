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
use App\Domain\Search\Operations\Input\ReindexSearchEngineInput;
use App\Domain\Search\Operations\ReindexSearchEngine;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
                'notice' => $providers->noticeFor($id),
                'providers' => $names->map(fn (string $name): array => [
                    'name' => $name,
                    'label' => $registry->find($name)?->manifest?->label() ?? $name,
                ])->values()->all(),
            ];
        }

        return Inertia::render('plugins/slots', ['slots' => $slots]);
    }

    /**
     * Asks the chosen search engine to take every published item again.
     */
    public function reindex(Request $request, ReindexSearchEngine $reindex): RedirectResponse
    {
        try {
            $reindex->execute(new ReindexSearchEngineInput(
                actorId: $request->user()?->getAuthIdentifier() === null ? null : (string) $request->user()->getAuthIdentifier(),
            ));
        } catch (ValidationException $refused) {
            return $this->refused($refused);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The search engine is being sent every published item.')]);

        return to_route('plugins.slots');
    }

    /**
     * Chooses the plugin for a slot, or none (an empty plugin), through ChooseSlotProvider.
     */
    public function choose(Request $request, string $slot, ChooseSlotProvider $choose): RedirectResponse
    {
        $plugin = $request->input('plugin');

        try {
            $choose->execute(new ChooseSlotProviderInput(
                slot: $slot,
                plugin: is_string($plugin) && $plugin !== '' ? $plugin : null,
                actorId: $request->user()?->getAuthIdentifier() === null ? null : (string) $request->user()->getAuthIdentifier(),
            ));
        } catch (ValidationException $refused) {
            return $this->refused($refused);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return to_route('plugins.slots');
    }

    /**
     * Goes back to the screen with the first refusal as an error toast.
     */
    private function refused(ValidationException $refused): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => (string) collect($refused->errors())->flatten()->first()]);

        return to_route('plugins.slots');
    }
}
