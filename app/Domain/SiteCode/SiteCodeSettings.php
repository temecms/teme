<?php

declare(strict_types=1);

namespace App\Domain\SiteCode;

use App\Domain\Cache\PageTags;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;

/**
 * Settings › Site code's settings group: ads.txt text served at /ads.txt
 * (I-064). Edited only on the Site code screen; administrators only.
 */
final class SiteCodeSettings
{
    public const string KEY = 'site_code';

    public const string ADS_TXT = 'ads_txt';

    /** 64 KB, the plan's ceiling for ads.txt. */
    public const int ADS_TXT_MAX = 65536;

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
            ],
            since: '0.8.0',
            cacheTags: [PageTags::url('/ads.txt')],
            screen: 'settings.site-code',
        );
    }
}
