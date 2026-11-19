<?php

/**
 * Where core's Settings pages draw a group or a setting that isn't theirs by
 * name (docs/architecture/admin-design.md, the parity sweep's items 12, 15 and 16).
 * Groups never move: each keeps its key, its values, its ability and how
 * it saves; only the page that draws it changes.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Settings;

final class SettingsPlacement
{
    /**
     * Core's groups drawn as a page of another name, by the page's key: the
     * group, the page's name and line, and the heading its form sits under.
     *
     * @var array<string, array{group: string, label: string, description: string, section: string|null}>
     */
    public const array RENAMED = [
        'writing' => ['group' => 'content', 'label' => 'Writing', 'description' => 'How long the trash keeps items and comments.', 'section' => null],
        'people' => ['group' => 'security', 'label' => 'People', 'description' => 'How everyone with an account signs in: password rules, sign-in limits and how long sessions last; and where people’s pictures come from.', 'section' => null],
    ];

    /**
     * Settings drawn on another group's page, as "{group}.{key}" => the
     * page's key: the brand colour on General, the home page on Reading.
     *
     * @var array<string, string>
     */
    public const array PLACED = [
        'design.accent' => 'general',
        'addresses.front_shows' => 'reading',
        'addresses.front_page_id' => 'reading',
        'addresses.posts_page_id' => 'reading',
        'modules.fediverse' => 'fediverse',
    ];

    /**
     * The key of the page that draws this group: the page that renames it,
     * or the group's own key.
     */
    public static function pageShowing(string $group): string
    {
        foreach (self::RENAMED as $page => $renamed) {
            if ($renamed['group'] === $group) {
                return $page;
            }
        }

        return $group;
    }

    /**
     * The key of the group this page draws as its own: the group it
     * renames, or the group of the page's own key.
     */
    public static function groupShownOn(string $page): string
    {
        return self::RENAMED[$page]['group'] ?? $page;
    }
}
