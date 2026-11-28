<?php

/**
 * Tells whoever may manage plugins when a provider slot's chosen plugin is no
 * longer enabled and installed, so Teme's own provider stands without anyone
 * having chosen it. A choice cleared by turning its plugin off says nothing
 * here: its notice on Provider choices already does.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Status\Checks;

use App\Domain\Plugins\PluginAccess;
use App\Domain\Plugins\Slots\PluginSlots;
use App\Domain\Plugins\Slots\SlotProviders;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;

final readonly class ProviderChoiceGone implements StatusCheck
{
    /**
     * A warning, below a plugin that can't run and above an update.
     */
    public const int PRIORITY = 45;

    public function __construct(private SlotProviders $providers) {}

    /**
     * The check's stable key, matched by Home's summary rows.
     *
     * @since 1.8.0
     */
    public function key(): string
    {
        return 'provider-choice-gone';
    }

    public function ability(): string
    {
        return PluginAccess::MANAGE;
    }

    /**
     * @since 1.8.0
     */
    public function label(): string
    {
        return __('Plugins');
    }

    /**
     * The banner for the first lost choice, linking to Provider choices (left out on that page itself).
     *
     * @since 1.8.0
     */
    public function banner(): ?StatusBanner
    {
        $lost = $this->providers->lost();

        if ($lost === []) {
            return null;
        }

        $slot = array_key_first($lost);
        $label = __(PluginSlots::all()[$slot]['label']);
        $onThePage = request()->routeIs('plugins.*');

        return new StatusBanner(
            'warning',
            __(':slot had a chosen plugin that is no longer enabled, so Teme’s own provider stands. Choose another, or none.', ['slot' => $label]),
            $onThePage ? null : __('Open Provider choices'),
            $onThePage ? null : route('plugins.slots'),
            self::PRIORITY,
        );
    }
}
