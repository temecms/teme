<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

use App\Domain\Settings\Queries\SettingsNavigation;
use App\Models\User;

/**
 * The Settings hub: it opens on the first Settings page the person may
 * open, and lists them all for the palette.
 */
final readonly class SettingsHub implements NavHub
{
    public function __construct(private SettingsNavigation $settings) {}

    /**
     * The first Settings page's address, or null when the person may open none.
     */
    public function firstHrefFor(User $user): ?string
    {
        return $this->pagesFor($user)[0]['href'] ?? null;
    }

    /**
     * The Settings pages this person may open, in the Settings menu's
     * order, each drawn with the Settings icon, a plugin's page with the
     * plugin's label. A page has no line of its own.
     *
     * @return list<array{key: string, label: string, icon: string, description: string, href: string, plugin?: string}>
     */
    public function pagesFor(User $user): array
    {
        return array_map(fn (array $page): array => [
            'key' => $page['key'],
            'label' => $page['label'],
            'icon' => 'settings',
            'description' => '',
            'href' => $page['href'],
            ...(str_starts_with($page['key'], 'plugin.') ? ['plugin' => $page['label']] : []),
        ], $this->settings->forUser($user->getKey()));
    }
}
