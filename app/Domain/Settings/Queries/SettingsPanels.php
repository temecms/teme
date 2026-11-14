<?php

declare(strict_types=1);

namespace App\Domain\Settings\Queries;

/**
 * The panels a Settings page splits its group into, where it splits it at
 * all (I-3128): General's two. Every other group page is one panel. Every
 * setting of a split group is in exactly one (SettingsPanelsDataTest).
 */
final class SettingsPanels
{
    /** @var list<array{title: string, description: string, keys: list<string>}> */
    public const array GENERAL = [
        ['title' => 'The site', 'description' => 'Its name and tagline, and where Teme sends notices.', 'keys' => ['site_title', 'tagline', 'admin_email']],
        ['title' => 'Time and language', 'description' => 'How dates are shown and scheduled, and the language the site is written in.', 'keys' => ['timezone', 'language']],
    ];

    /**
     * The page's panels, each its title, its line and its settings' keys in
     * order, or null for a page drawn as one panel.
     *
     * @return list<array{title: string, description: string, keys: list<string>}>|null
     */
    public static function for(string $page): ?array
    {
        return $page === 'general' ? self::GENERAL : null;
    }
}
