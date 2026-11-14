<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Users;

use App\Domain\Users\Roles;

/**
 * Where a WordPress role goes in Teme (I-340): WordPress's own roles by
 * name, a subscriber nowhere, and a custom role where the run's choices
 * put it, or where a plugin's well-known role belongs. A role it can't
 * place is never given a writing role by guess.
 */
final class WordPressRoles
{
    /** The choice that leaves a custom role's people behind, as subscribers are. */
    public const string NONE = 'none';

    /**
     * WordPress's own roles, and the Teme role each becomes; null for none.
     *
     * @var array<string, string|null>
     */
    public const array CORE = [
        'administrator' => 'administrator',
        'editor' => 'editor',
        'author' => 'author',
        'contributor' => 'contributor',
        'subscriber' => null,
    ];

    /**
     * Plugins' roles whose place is known: Yoast SEO registers both as
     * copies of Editor.
     *
     * @var array<string, string>
     */
    public const array KNOWN = [
        'wpseo_manager' => 'editor',
        'wpseo_editor' => 'editor',
    ];

    /**
     * What a role must be able to do for each Teme role, from the lowest;
     * each also needs everything below it. WordPress's own roles hold these.
     *
     * @var array<string, list<string>>
     */
    private const array LADDER = [
        'contributor' => ['edit_posts'],
        'author' => ['upload_files', 'publish_posts'],
        'editor' => ['edit_others_posts', 'publish_pages'],
        'administrator' => ['manage_options', 'edit_users'],
    ];

    /**
     * The nearest Teme role at or below a role with these WordPress
     * capabilities: the highest whose needs it fully holds; null when it
     * can't even write, as a reader can't.
     *
     * @param  list<string>  $capabilities
     */
    public static function nearest(array $capabilities): ?string
    {
        $nearest = null;
        $needs = [];

        foreach (self::LADDER as $role => $more) {
            $needs = [...$needs, ...$more];

            if (array_diff($needs, $capabilities) !== []) {
                break;
            }

            $nearest = $role;
        }

        return $nearest;
    }

    /**
     * Where a person with these WordPress roles goes: the highest Teme role
     * any of them becomes (null for none); the custom roles it placed, by a
     * choice or because it knows them; and those it couldn't place.
     *
     * @param  list<string>  $roles
     * @param  array<array-key, mixed>  $choices  a custom role's key to a Teme role's, or NONE
     *
     * @return array{role: string|null, placed: array<string, string|null>, unknown: list<string>}
     */
    public static function place(array $roles, array $choices): array
    {
        // Roles::LABELS lists the most powerful first.
        $rank = array_flip(array_keys(Roles::LABELS));
        $best = null;
        $placed = [];
        $unknown = [];

        foreach ($roles as $wordpress) {
            $choice = $choices[$wordpress] ?? null;

            if (array_key_exists($wordpress, self::CORE)) {
                $teme = self::CORE[$wordpress];
            } elseif ($choice === self::NONE || (is_string($choice) && isset($rank[$choice]))) {
                $teme = $choice === self::NONE ? null : $choice;
                $placed[$wordpress] = $teme;
            } elseif (isset(self::KNOWN[$wordpress])) {
                $teme = self::KNOWN[$wordpress];
                $placed[$wordpress] = $teme;
            } else {
                $unknown[] = $wordpress;

                continue;
            }

            if ($teme !== null && ($best === null || $rank[$teme] < $rank[$best])) {
                $best = $teme;
            }
        }

        return ['role' => $best, 'placed' => $placed, 'unknown' => $unknown];
    }
}
