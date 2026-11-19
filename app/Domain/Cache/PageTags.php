<?php

/**
 * The page cache's tags (docs/studies/page-cache.md §3.4, I-263): what a
 * page records for everything it shows, and what an Operation purges for a
 * change. A thing as shown is `{kind}:{id}`; a list is named by its query,
 * never by the IDs it happened to return. Built from rows and the
 * registries, never from a quoted type name.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cache;

use App\Domain\Addresses\PublicRequestPath;
use Carbon\CarbonInterface;

final class PageTags
{
    /** Every stored page's, without a row of its own: purging it purges them all. */
    public const string EVERYTHING = '*';

    /** The site's own settings every page shows: its name, tagline, language and clock. */
    public const string SETTINGS = 'settings';

    /** The design every page is drawn in: the theme, its settings, the accent and the menus in its places. */
    public const string THEME = 'theme';

    /** The Discussion settings a thread is drawn by: how many conversations a page holds, how deep replies go, what the form asks (1.8b's D6). */
    public const string DISCUSSION = 'discussion';

    /** Search and social's settings, which every page the document shell draws reads, its robots meta at least. */
    public const string SEO = 'seo';

    /**
     * Every published article page that asks contributors for insertions, drawn or not (I-064).
     *
     * @since 1.0.0
     */
    public const string INSERTIONS = 'insertions';

    /**
     * Every stored "not found" page at an address a plugin's root file could answer (docs/guides/developers/plugins/root-files.md).
     *
     * @since 1.0.0
     */
    public const string ROOT_FILES = 'root-files';

    /**
     * Every feed, which carries the links plugins add to it (docs/plans/plugin-sockets-1.md, A3).
     *
     * @since 1.0.0
     */
    public const string FEED_LINKS = 'feed-links';

    /**
     * Every public page that asks contributors for page insertions, drawn or not.
     *
     * @since 1.0.0
     */
    public const string PAGE_INSERTIONS = 'page-insertions';

    /**
     * Whether the tag names a list by its query (a type's, a term's, an
     * author's, a year's, a month's, an item's comments, the list of a
     * taxonomy's terms or the list of a page's children) rather than a thing as shown. A change to a list
     * sets the pages carrying its tag aside, served while one request draws
     * each afresh; a change to a thing removes every page that shows it
     * (docs/studies/page-cache.md §3.9). A tag not named here is a thing's,
     * so what later parts add is removed, never served stale.
     */
    public static function isList(string $tag): bool
    {
        return str_starts_with($tag, 'list:')
            || str_starts_with($tag, 'author:')
            || str_starts_with($tag, 'date:')
            || (str_starts_with($tag, 'term:') && substr_count($tag, ':') === 2)
            || str_starts_with($tag, 'comments:')
            || str_starts_with($tag, 'taxonomy:')
            || str_starts_with($tag, 'children:');
    }

    /**
     * Every page at this address, in any case or query: `url:` and 16 hex
     * characters of the SHA-256 of the address in the form addresses are
     * stored in.
     */
    public static function url(string $path): string
    {
        return 'url:'.substr(hash('sha256', PublicRequestPath::normalise($path)), 0, 16);
    }

    /**
     * A content item as shown: its page, a card, a link to it or a menu item.
     */
    public static function content(string $id): string
    {
        return 'content:'.$id;
    }

    /**
     * The list of a page's children (I-3363): what a page that shows the
     * pages under it records, and what a child's change sets aside.
     */
    public static function childrenOf(string $parentId): string
    {
        return 'children:'.$parentId;
    }

    /**
     * The list of a type's published items: the latest posts, a search.
     */
    public static function listOf(string $type): string
    {
        return 'list:'.$type;
    }

    /**
     * A term as shown: its name and address, as a kicker, a tag, a menu item
     * or an archive's title.
     */
    public static function term(string $id): string
    {
        return 'term:'.$id;
    }

    /**
     * The list of a taxonomy's terms, as the API gives it: set aside when a
     * term of it is created, renamed, moved or deleted.
     */
    public static function termsOf(string $taxonomy): string
    {
        return 'taxonomy:'.$taxonomy;
    }

    /**
     * The list of what is filed under a term: its archive, and the related
     * posts it gives.
     */
    public static function filedUnder(string $taxonomy, string $termId): string
    {
        return 'term:'.$taxonomy.':'.$termId;
    }

    /**
     * A person as shown: their name, bio and address, in a byline or on
     * their page; by an account's ID, or a person's with no account (I-3366).
     */
    public static function person(string $id): string
    {
        return 'person:'.$id;
    }

    /**
     * The list of what a person is credited on: their archive; by an
     * account's ID, or a person's with no account.
     */
    public static function author(string $id): string
    {
        return 'author:'.$id;
    }

    /**
     * The list of what was published in a year, on the site's clock.
     */
    public static function year(int $year): string
    {
        return sprintf('date:%04d', $year);
    }

    /**
     * The list of what was published in a month, and so on each of its
     * days, on the site's clock.
     */
    public static function month(int $year, int $month): string
    {
        return sprintf('date:%04d:%02d', $year, $month);
    }

    /**
     * The lists a moment falls in: its year's and its month's. The moment
     * must already be on the site's clock (SiteDates::inSite()).
     *
     * @return list<string>
     */
    public static function dates(CarbonInterface $moment): array
    {
        return [self::year($moment->year), self::month($moment->year, $moment->month)];
    }

    /**
     * A media item as shown.
     */
    public static function media(string $id): string
    {
        return 'media:'.$id;
    }

    /**
     * A menu, by its handle, made once from its name and never changed
     * (docs/architecture/themes.md).
     */
    public static function menu(string $handle): string
    {
        return 'menu:'.$handle;
    }

    /**
     * A region, by its content slug, `{theme}--{region}`.
     */
    public static function region(string $slug): string
    {
        return 'region:'.$slug;
    }

    /**
     * The list of an item's comments shown on its pages (1.8b's D5): a
     * comment arriving sets those pages aside. PageTags::isList() names it.
     */
    public static function comments(string $contentId): string
    {
        return 'comments:'.$contentId;
    }

    /**
     * A comment as shown in its item's thread (1.8b's D5): a comment leaving
     * removes the pages that show it.
     */
    public static function comment(string $commentId): string
    {
        return 'comment:'.$commentId;
    }

    /**
     * The pages that show a translation group's versions, such as the links
     * between them (I-3360): a version arriving or leaving sets them aside.
     */
    public static function translations(string $group): string
    {
        return 'translations:'.$group;
    }
}
