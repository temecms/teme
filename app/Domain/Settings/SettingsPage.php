<?php

declare(strict_types=1);

namespace App\Domain\Settings;

/**
 * A page under Settings that isn't a settings group, such as Site code
 * (I-863): a tab after the groups, for whoever holds its ability.
 * Registered under TAG from its part's own provider.
 */
interface SettingsPage
{
    public const string TAG = 'teme.settings.pages';

    /**
     * Its tab's key, which is also its address under /admin/settings/; never
     * a settings group's key.
     */
    public function key(): string;

    /**
     * Its tab's label.
     */
    public function label(): string;

    /**
     * The permission whose holders see its tab.
     */
    public function ability(): string;
}
