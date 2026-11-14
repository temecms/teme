<?php

declare(strict_types=1);

namespace App\Domain\Api\Llms;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Api\Delivery\DeliverySwitches;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Seo\TitlesAndListing;
use App\Domain\Seo\WholeSite;
use App\Domain\Sitemaps\Queries\ListedItems;
use App\Domain\Types\ContentTypeRegistry;
use Teme\Contracts\Settings\SettingsReader;

/**
 * The text of /llms.txt (I-713): the site's name and tagline, then a section
 * for each public type with addresses, its newest listed items each linking
 * to the plain-text copy of its page. It lists what the sitemap lists
 * (I-1118), and only items whose `.md` address the route can answer.
 */
final class LlmsTxt
{
    /** The most items a section lists. */
    public const int MOST_A_SECTION = 100;

    /**
     * The file's text, or null while its switch is off or the site
     * discourages search engines, when the site's own 404 page answers.
     */
    public static function text(): ?string
    {
        if (! DeliverySwitches::llmsOn() || WholeSite::discouraged()) {
            return null;
        }

        $settings = app(SettingsReader::class);
        $addresses = AddressSettings::current();
        $items = app(ListedItems::class);
        $record = app(RenderRecord::class);
        $root = rtrim(url('/'), '/');
        $tagline = self::oneLine((string) $settings->string('general', 'tagline'));

        $lines = ['# '.self::oneLine((string) $settings->string('general', 'site_title')), ''];

        if ($tagline !== '') {
            $lines[] = '> '.$tagline;
            $lines[] = '';
        }

        $lines[] = "This site's published content, newest first in each section. Each link opens a plain-text copy of the page.";
        $lines[] = '';

        foreach (app(ContentTypeRegistry::class)->all() as $type) {
            if (! $type->public || ! $addresses->hasPattern($type->key) || TitlesAndListing::hidesType($type->key)) {
                continue;
            }

            $record->tag(PageTags::listOf($type->key));
            $section = [];

            foreach ($items->latestListed($type->key, self::MOST_A_SECTION) as $item) {
                if (! MarkdownPages::answers($item['path'], $addresses)) {
                    continue;
                }

                $excerpt = $item['excerpt'] === null ? '' : ': '.self::oneLine($item['excerpt']);
                $section[] = '- ['.self::escapeTitle(self::oneLine($item['title'])).']('.$root.'/'.$item['path'].'.md)'.$excerpt;
            }

            if ($section !== []) {
                array_push($lines, '## '.self::oneLine($type->plural), '');
                array_push($lines, ...$section);
                $lines[] = '';
            }
        }

        if (DeliverySwitches::apiOn()) {
            array_push($lines, '## API', '', '- [Delivery API]('.$root.'/api/v1): The same content as JSON.', '');
        }

        return implode("\n", array_slice($lines, 0, -1))."\n";
    }

    /**
     * The words on one line: each newline and tab a space.
     */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\R|\t/u', ' ', $text));
    }

    /**
     * A link's text, with the characters that would end or change it escaped.
     */
    private static function escapeTitle(string $title): string
    {
        return (string) preg_replace('/[\[\]\\\\]/', '\\\\$0', $title);
    }
}
