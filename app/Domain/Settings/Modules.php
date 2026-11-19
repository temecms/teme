<?php

/**
 * The optional parts of Teme an administrator switches on or off (I-077):
 * one key per module in the `modules` settings group, its value an enum the
 * module declares (docs/studies/settings-registry.md §3), each shown at the
 * top of its own feature's settings page (I-485). Comments are the first.
 * A switch changes every page, so a change purges them all (I-484).
 * Its switch is edited on Settings › Discussion, with its own Operation
 * (1.8d's D2, D3).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Cache\PageTags;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsReader;

final class Modules
{
    public const string COMMENTS = 'comments';

    /**
     * @since 1.0.0
     */
    public const string FEDIVERSE = 'fediverse';

    public const string ON = 'on';

    public const string OFF = 'off';

    public static function group(): SettingsGroup
    {
        return new SettingsGroup(
            key: 'modules',
            label: 'Modules',
            description: 'The optional parts of the site an administrator switches on or off. Each switch sits at the top of its own feature\'s settings page.',
            section: 'modules',
            ability: 'settings.modules.manage',
            settings: [
                Setting::select(self::COMMENTS, 'Comments', 'Off, the site takes no comments, and visitors see no comments, form or counts. Comments leaves the menu and the editor. Stored comments and these settings are kept.', '0.1.0', [self::ON => 'On', self::OFF => 'Off']),
                // Off first, so off by default; edited on Settings › Fediverse (I-058).
                Setting::select(self::FEDIVERSE, 'Fediverse', 'On, people on Mastodon and other fediverse servers can follow the site. Off, it is paused: nothing is sent or received, and its followers and keys are kept.', '1.0.0', [self::OFF => 'Off', self::ON => 'On']),
            ],
            since: '0.1.0',
            screen: 'settings.discussion',
            cacheTags: [PageTags::EVERYTHING],
        );
    }

    /**
     * Whether the module is switched on.
     */
    public static function on(string $module): bool
    {
        return app(SettingsReader::class)->string('modules', $module) === self::ON;
    }
}
