<?php

/**
 * The `directory` settings group: whether Add plugin and Add theme read
 * Teme's plugin and theme directory. On by default; off, nothing is asked
 * of the directory and nothing is installed from it.
 *
 * @package Teme
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Directory;

use App\Domain\Extensions\ExtensionsAccess;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsReader;

final class DirectorySettings
{
    public const string GROUP = 'directory';

    public const string ENABLED = 'enabled';

    /**
     * The group, as its provider registers it.
     */
    public static function group(): SettingsGroup
    {
        return new SettingsGroup(
            key: self::GROUP,
            label: 'Plugin and theme directory',
            description: 'Whether Add plugin and Add theme show the packages listed in Teme’s directory.',
            section: 'directory',
            ability: ExtensionsAccess::INSTALL,
            settings: [
                Setting::boolean(self::ENABLED, 'Use the directory', 'On, Add plugin and Add theme list the directory’s packages and can install them; each list is checked against the directory’s signature first. Off, Teme asks nothing of the directory and installs nothing from it.', '1.2.0')
                    ->default(true),
            ],
            since: '1.2.0',
        );
    }

    /**
     * Whether the directory is switched on for this site.
     */
    public static function enabled(): bool
    {
        return app(SettingsReader::class)->bool(self::GROUP, self::ENABLED);
    }
}
