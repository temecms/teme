<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Cache\PageTags;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsReader;

/**
 * The optional parts of Teme an administrator switches on or off (I-077):
 * one key per module in the `modules` settings group, its value an enum the
 * module declares (docs/studies/settings-registry.md §3), each shown at the
 * top of its own feature's settings page (I-485). Comments are the first.
 * A switch changes every page, so a change purges them all (I-484).
 * Its switch is edited on Settings › Discussion, with its own Operation
 * (1.8d's D2, D3).
 */
final class Modules
{
    public const string COMMENTS = 'comments';

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
