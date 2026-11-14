<?php

declare(strict_types=1);

namespace App\Domain\RobotsTxt;

use App\Domain\Seo\SearchAndSocial;
use App\Domain\Sitemaps\Sitemaps;
use Teme\Contracts\Settings\SettingsReader;

/**
 * robots.txt as Teme writes it (I-300): crawlers kept from the admin and
 * core's endpoints, an administrator's extra rules, and, at one switch, each
 * AI training crawler asked to stay away. Read from Search and social's
 * settings, so the copy the page cache stores goes when they change.
 */
final class RobotsTxt
{
    /** The site may be searched, not used to train AI models, in Cloudflare's Content Signals vocabulary. */
    public const string CONTENT_SIGNAL = 'Content-Signal: search=yes, ai-train=no';

    /**
     * The file, ending in one line break. The same whatever Discourage
     * search engines says but for its last line, the sitemap's, left out
     * while the sitemaps are off: every page then says noindex, which a
     * crawler kept out would never read (I-883).
     */
    public static function text(): string
    {
        $settings = app(SettingsReader::class);
        $askTrainersAway = $settings->bool(SearchAndSocial::KEY, RobotsTxtSection::AI);
        $own = ['User-agent: *'];

        // In the group every crawler reads; a parser skips a line it doesn't know.
        if ($askTrainersAway) {
            $own[] = self::CONTENT_SIGNAL;
        }

        $own[] = 'Disallow: /admin/';
        $own[] = 'Disallow: /_teme/';
        $blocks = [implode("\n", $own)];

        // Right after Teme's group, so a bare Disallow: line joins it.
        $extra = RobotsRules::kept($settings->string(SearchAndSocial::KEY, RobotsTxtSection::EXTRA));

        if ($extra !== []) {
            $blocks[] = implode("\n", $extra);
        }

        if ($askTrainersAway) {
            $blocks[] = "# AI training crawlers, from Teme's list\n".implode("\n\n", array_map(
                fn (string $agent): string => "User-agent: {$agent}\nDisallow: /",
                AiTrainingCrawlers::agents(),
            ));
        }

        // Last, as crawlers expect it; the sitemaps are off while the site discourages search engines.
        if (Sitemaps::on()) {
            $blocks[] = 'Sitemap: '.rtrim(url('/'), '/').'/sitemap.xml';
        }

        return implode("\n\n", $blocks)."\n";
    }
}
