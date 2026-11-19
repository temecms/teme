<?php

/**
 * The `fediverse` settings group (I-058): who can be followed, the blog,
 * its authors, or both. It has its own screen, Settings › Fediverse, so
 * only ChangeFediverseSettings writes it; the switch is `modules.fediverse`.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse;

use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsReader;

final class FediverseSettingsGroup
{
    public const string KEY = 'fediverse';

    public const string FOLLOWABLE = 'followable';

    public const string BLOG = 'blog';

    public const string AUTHORS = 'authors';

    public const string BOTH = 'both';

    public static function group(): SettingsGroup
    {
        return new SettingsGroup(
            key: self::KEY,
            label: 'Fediverse',
            description: 'Let people on Mastodon and other fediverse servers follow the site and its authors.',
            section: 'fediverse',
            ability: 'settings.fediverse.manage',
            settings: [
                Setting::select(self::FOLLOWABLE, 'Who can be followed', 'The blog’s account shares every post. An author’s account shares only their own, once you turn it on below.', '1.0.0', [
                    self::BLOG => 'The blog',
                    self::AUTHORS => 'Each author',
                    self::BOTH => 'The blog and each author',
                ]),
            ],
            since: '1.0.0',
            screen: 'settings.fediverse',
        );
    }

    /**
     * Who can be followed now: blog, authors or both.
     */
    public static function followable(): string
    {
        return app(SettingsReader::class)->string(self::KEY, self::FOLLOWABLE);
    }

    /**
     * Whether the blog's account can be followed.
     */
    public static function blogFollowable(): bool
    {
        return self::followable() !== self::AUTHORS;
    }

    /**
     * Whether authors' accounts can be followed.
     */
    public static function authorsFollowable(): bool
    {
        return self::followable() !== self::BLOG;
    }
}
