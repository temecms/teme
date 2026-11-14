<?php

declare(strict_types=1);

namespace App\Domain\Seo;

use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Search and social's section "The whole site": the one switch that asks
 * search engines to stay away from every page (I-291), for a site that
 * isn't ready to be found.
 */
final class WholeSite implements SearchAndSocialSection
{
    public const string DISCOURAGE = 'discourage_search_engines';

    public function order(): int
    {
        return 200;
    }

    public function key(): string
    {
        return 'whole-site';
    }

    public function label(): string
    {
        return 'The whole site';
    }

    public function settings(): array
    {
        return [
            Setting::boolean(self::DISCOURAGE, 'Discourage search engines', 'Every page asks search engines not to list it and the sitemap is switched off, so search engines drop the site. For a site that isn’t ready to be found.', '0.1.0'),
        ];
    }

    /**
     * Whether the site asks search engines to stay away: read through the
     * settings, so a page that asks carries the group's tag.
     */
    public static function discouraged(): bool
    {
        return app(SettingsReader::class)->bool(SearchAndSocial::KEY, self::DISCOURAGE);
    }
}
