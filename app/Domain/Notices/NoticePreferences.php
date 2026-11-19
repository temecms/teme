<?php

/**
 * Declares one list preference for every kind a person may turn off (I-110):
 * the channels they keep, with `[]` meaning off. A required kind declares
 * none, so a stored value can never narrow it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Notices;

use App\Domain\Preferences\PreferenceDefinition;
use App\Domain\Preferences\PreferenceRegistry;

final class NoticePreferences
{
    /** What every notice choice's preference key starts with. */
    public const string PREFIX = 'notifications.';

    public static function declare(PreferenceRegistry $registry): void
    {
        foreach (app(NoticeRegistry::class)->listed() as $kind) {
            $channels = $kind->channels;

            $registry->register(new PreferenceDefinition(
                self::PREFIX.$kind->key, 'list', $kind->defaults, $kind->label,
                allowed: fn (): array => $channels,
                maxItems: count($channels),
            ));
        }
    }
}
