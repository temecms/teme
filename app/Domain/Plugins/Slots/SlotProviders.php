<?php

/**
 * Which plugin fills a provider slot now (docs/guides/developers/plugins/provider-slots.md):
 * the administrator's choice while that plugin is enabled and still provides
 * the slot, else the one enabled plugin that provides it alone, else none,
 * which leaves core's default in place.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Slots;

use App\Domain\Plugins\Images\ImageServiceDeclaration;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class SlotProviders
{
    public function __construct(private PluginRegistry $registry) {}

    /**
     * The name of the plugin that fills the slot, or null for core's default.
     */
    public function chosenFor(string $slot): ?string
    {
        $providers = $this->providers($slot);
        $chosen = DB::table('plugin_slot_choices')->where('slot', $slot)->value('plugin');

        if (is_string($chosen) && $providers->contains($chosen)) {
            return $chosen;
        }

        return $providers->count() === 1 ? $providers->first() : null;
    }

    /**
     * The notice a slot keeps when its chosen plugin was turned off, or null.
     */
    public function noticeFor(string $slot): ?string
    {
        $notice = DB::table('plugin_slot_choices')->where('slot', $slot)->value('notice');

        return is_string($notice) && $notice !== '' ? $notice : null;
    }

    /**
     * Whether a manifest provides the slot: by its `provides`, or, for the image
     * service, by an imageService declaration made before `provides` existed.
     */
    public static function provides(Manifest $manifest, string $slot): bool
    {
        if (in_array($slot, (array) ($manifest->contributes()['provides'] ?? []), true)) {
            return true;
        }

        return $slot === PluginSlots::IMAGE_SERVICE && ImageServiceDeclaration::of($manifest) !== null;
    }

    /**
     * The slots whose chosen plugin is no longer enabled and providing, each with
     * the plugin chosen for it. A choice cleared when its plugin was turned off
     * is not one of these.
     *
     * @return array<string, string>
     */
    public function lost(): array
    {
        $lost = [];

        foreach (DB::table('plugin_slot_choices')->where('plugin', '!=', '')->get(['slot', 'plugin']) as $choice) {
            $slot = (string) $choice->slot;

            if (! PluginSlots::exists($slot) || $this->providers($slot)->contains((string) $choice->plugin)) {
                continue;
            }

            $lost[$slot] = (string) $choice->plugin;
        }

        return $lost;
    }

    /**
     * The names of the enabled plugins whose manifests provide the slot, in name order.
     *
     * @return Collection<int, string>
     */
    public function providers(string $slot): Collection
    {
        return collect(PluginRecord::query()->where('state', PluginRecord::ENABLED)->orderBy('name')->pluck('name'))
            ->filter(function (string $name) use ($slot): bool {
                $manifest = $this->registry->find($name)?->manifest;

                return $manifest !== null && self::provides($manifest, $slot);
            })
            ->values();
    }
}
