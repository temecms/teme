<?php

declare(strict_types=1);

namespace App\Domain\Preferences\Queries;

use App\Domain\Preferences\PreferenceRegistry;
use App\Models\User;

/**
 * One person's preferences, as every screen reads them: each declared key,
 * with what they set or else its default. A stored value that is no longer
 * allowed, or no longer declared, is passed over; a stored list keeps the
 * items still allowed, so a removed menu entry leaves no hole. It reads only.
 */
final class PreferencesFor
{
    /**
     * Every declared preference for this person, keyed by preference key, in
     * declaration order.
     *
     * @return array<string, bool|int|string|list<string>>
     */
    public static function user(User $user): array
    {
        /** @var array<string, mixed> $stored */
        $stored = (array) ($user->preferences ?? []);
        $resolved = [];

        foreach (app(PreferenceRegistry::class)->all() as $key => $definition) {
            $value = $definition->keepAllowedItems(array_key_exists($key, $stored) ? $stored[$key] : $definition->default);

            /** @var bool|int|string|list<string> $allowed an allowed value is one of these */
            $allowed = $definition->allows($value) ? $value : $definition->default;
            $resolved[$key] = $allowed;
        }

        return $resolved;
    }
}
