<?php

/**
 * Settings › Site code's settings group: ads.txt text or a redirect at
 * /ads.txt (I-064). Edited only on the Site code screen; administrators only.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\SiteCode;

use App\Domain\Cache\PageTags;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;

final class SiteCodeSettings
{
    public const string KEY = 'site_code';

    public const string ADS_TXT = 'ads_txt';

    public const string ADS_TXT_MODE = 'ads_txt_mode';

    public const string ADS_TXT_REDIRECT = 'ads_txt_redirect';

    public const string MODE_TEXT = 'text';

    public const string MODE_REDIRECT = 'redirect';

    /** 64 KB, the plan's ceiling for ads.txt. */
    public const int ADS_TXT_MAX = 65536;

    /** Ceiling for a redirect address, shorter than a generic url setting. */
    public const int ADS_TXT_REDIRECT_MAX = 2000;

    public static function group(): SettingsGroup
    {
        return new SettingsGroup(
            key: self::KEY,
            label: 'Site code',
            description: 'Code added to every public page, and the ads.txt file for ad buyers.',
            section: 'site-code',
            ability: SiteCode::ABILITY,
            settings: [
                Setting::textarea(self::ADS_TXT, 'ads.txt', 'Shown at /ads.txt for advertising buyers. Leave empty for a 404.', '0.8.0')
                    ->maxLength(self::ADS_TXT_MAX),
                Setting::select(self::ADS_TXT_MODE, 'ads.txt mode', 'Serve the text, or send buyers to another host’s ads.txt.', '1.0.0', [
                    self::MODE_TEXT => 'Text',
                    self::MODE_REDIRECT => 'Send to another address',
                ]),
                Setting::url(self::ADS_TXT_REDIRECT, 'ads.txt redirect', 'The https address on another host to send /ads.txt to.', '1.0.0'),
            ],
            since: '0.8.0',
            cacheTags: [PageTags::url('/ads.txt')],
            screen: 'settings.site-code',
        );
    }
}
