<?php

declare(strict_types=1);

namespace App\Domain\ManageApi;

use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsReader;

/**
 * The management API's settings group: its own switch, off until an
 * administrator turns it on (I-485), and how many requests a minute one
 * key may make (docs/proposals/management-api.md). Drawn on Settings ›
 * Management API, at the group's own address, and saved through
 * UpdateSettings as the generic form saves any group.
 */
final class ManageApiSettings
{
    /** The group's key. */
    public const string KEY = 'management_api';

    /** Whether /api/manage/v1 answers. */
    public const string ENABLED = 'enabled';

    /** Requests a minute one key may make. */
    public const string PER_MINUTE = 'per_minute';

    /**
     * The group as Settings registers it.
     */
    public static function group(): SettingsGroup
    {
        return new SettingsGroup(
            key: self::KEY,
            label: 'Management API',
            description: 'Lets outside tools create drafts, upload media and publish, each with a key you make here.',
            section: 'site',
            ability: ManageApiAccess::ABILITY,
            settings: [
                Setting::boolean(self::ENABLED, 'Turn on the management API', 'Lets outside tools sign in with a key at /api/manage/v1. While it is off, every key is refused.', '0.9.0')
                    ->default(false),
                Setting::number(self::PER_MINUTE, 'Requests a minute per key', 'How many requests one key may make in a minute before it is asked to wait.', '0.9.0')
                    ->default(60)->min(1)->max(1000),
            ],
            since: '0.9.0',
        );
    }

    /**
     * Whether the management API answers.
     */
    public static function on(): bool
    {
        return app(SettingsReader::class)->bool(self::KEY, self::ENABLED);
    }

    /**
     * Requests a minute one key may make.
     */
    public static function perMinute(): int
    {
        return max(1, app(SettingsReader::class)->int(self::KEY, self::PER_MINUTE));
    }
}
