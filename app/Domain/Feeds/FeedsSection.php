<?php

/**
 * Search and social's section "Feeds": how many items each feed holds and
 * whether it carries the full text or the excerpt (I-874). Read through the
 * settings, so a feed carries the group's tag and a change removes it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds;

use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SearchAndSocialSection;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;

final class FeedsSection implements SearchAndSocialSection
{
    public const string ITEMS = 'feed_items';

    public const string CONTENT = 'feed_content';

    public const string FULL = 'full';

    public const string EXCERPT = 'excerpt';

    public function order(): int
    {
        return 600;
    }

    public function key(): string
    {
        return 'feeds';
    }

    public function label(): string
    {
        return 'Feeds';
    }

    public function settings(): array
    {
        return [
            Setting::number(self::ITEMS, 'Items in feeds', 'How many of the newest items each feed holds, from 1 to 100.', '0.1.0')
                ->default(10)->min(1)->max(100)->unit('items'),
            Setting::select(self::CONTENT, 'Feeds show', 'The full text can be read in a feed reader; the excerpt sends readers to the site to read on.', '0.1.0', [
                self::FULL => 'The full text',
                self::EXCERPT => 'The excerpt only',
            ]),
        ];
    }

    /**
     * How many items a feed holds.
     */
    public static function items(): int
    {
        return app(SettingsReader::class)->int(SearchAndSocial::KEY, self::ITEMS);
    }

    /**
     * Whether a feed carries each item's full text; false, only its excerpt.
     */
    public static function fullText(): bool
    {
        return app(SettingsReader::class)->string(SearchAndSocial::KEY, self::CONTENT) === self::FULL;
    }
}
