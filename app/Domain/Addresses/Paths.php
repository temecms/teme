<?php

/**
 * Lookups in the path table: a target's current address, the row at an
 * address, whether an address is already someone else's, and which
 * account a target names.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Addresses;

use App\Domain\Addresses\Models\Path;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\DB;

final class Paths
{
    /**
     * First segments the router already answers before the public fallback:
     * an item there would never be served. `_teme` holds core's own
     * endpoints, such as /_teme/session (slice 1.7c).
     *
     * @var list<string>
     */
    public const array RESERVED = ['admin', 'preview', 'up', 'storage', 'build', '_teme'];

    /**
     * First segments the public site's own routes answer: the feed, the
     * sitemap, robots.txt, the API, llms.txt and the fediverse's addresses, new
     * and the WordPress plugin's (I-058). No item and no address setting may take one,
     * as with RESERVED; unlike RESERVED's, what they answer is the public
     * site's, which the page cache may store.
     *
     * @var list<string>
     */
    public const array SITE_ROUTES = ['feed', 'sitemap.xml', 'robots.txt', 'ads.txt', 'api', 'llms.txt', 'ap', 'wp-json'];

    /**
     * Every kind of target this release writes to the path table and
     * answers: an item, a term, an account, a person with no account and a
     * type's archive page. A
     * later release may add one; the places that decide what each kind
     * answers name this list, so a search for it finds where it must join.
     *
     * @var list<string>
     */
    public const array KINDS = ['content', 'term', 'user', 'person', TypeArchives::TARGET];

    /**
     * An account's ID as the users table holds one, and so as an account's
     * row in the path table names it: anchored, with no delimiters, so PHP's
     * preg_match() and PostgreSQL's ~ read it alike.
     */
    public const string ACCOUNT_ID = '^[1-9][0-9]{0,17}$';

    /**
     * Whether an address starting with this segment is the site's own, never
     * an item's: the router's (RESERVED), the public site's routes'
     * (SITE_ROUTES) or another language's prefix.
     */
    public static function taken(string $segment): bool
    {
        return self::reserved($segment)
            // Every other language's prefix, shown or hidden (I-3360).
            || app(SiteLanguages::class)->byPrefix($segment) !== null;
    }

    /**
     * Whether the router (RESERVED) or the public site's routes
     * (SITE_ROUTES) answer addresses starting with this segment: taken()
     * without the languages' prefixes, so it reads nothing.
     */
    public static function reserved(string $segment): bool
    {
        return in_array($segment, self::RESERVED, true) || in_array($segment, self::SITE_ROUTES, true);
    }

    /**
     * The target's current address, or null when it has none.
     */
    public static function current(string $type, string $id): ?string
    {
        $path = DB::table('paths')->where('target_type', $type)->where('target_id', $id)->where('is_current', true)->value('path');

        return $path === null ? null : (string) $path;
    }

    /**
     * The account a target names: its ID when the target is an account's
     * and the ID is written as the users table holds one, or null for any
     * other kind, a kind a later release adds among them, or any other ID.
     */
    public static function account(string $type, string $id): ?int
    {
        return $type === 'user' && preg_match('/'.self::ACCOUNT_ID.'/', $id) === 1 ? (int) $id : null;
    }

    /**
     * Whether this release knows the target: a kind of KINDS, and for an
     * account an ID of ACCOUNT_ID's form.
     */
    public static function known(string $type, string $id): bool
    {
        return in_array($type, self::KINDS, true) && ($type !== 'user' || self::account($type, $id) !== null);
    }

    /**
     * The row at this address, current or history, or null.
     */
    public static function find(string $path): ?Path
    {
        return Path::query()->where('path', $path)->first();
    }

    /**
     * Why this target may not have this address, in words that name what
     * holds it: the site itself, another target's current address, or a
     * type's archive page. Null when it is free, or held only as someone's
     * history. $language is a target's not written yet, as `language`
     * will store it; a written target's own is read from its row.
     */
    public static function conflict(string $path, string $type, string $id, ?string $language = null): ?string
    {
        $shown = $path === '' ? '/' : '/'.$path.'/';
        $segments = explode('/', $path);
        $first = $segments[0];

        // A language's own target under its prefix: what follows the prefix
        // is checked as the default language's address would be (I-3360).
        $prefixed = app(SiteLanguages::class)->byPrefix($first);

        if ($prefixed !== null && (self::languageOf($type, $id) ?? $language) === $prefixed) {
            $first = $segments[1] ?? null;
        }

        if ($first !== null && self::taken($first)) {
            return __('The address :address is used by the site itself.', ['address' => $shown]);
        }

        $holder = DB::table('paths')->where('path', $path)->where('is_current', true)->first(['target_type', 'target_id']);

        if ($holder !== null) {
            return $holder->target_type === $type && (string) $holder->target_id === $id
                ? null
                : __('The address :address is used by :holder.', ['address' => $shown, 'holder' => self::describe((string) $holder->target_type, (string) $holder->target_id)]);
        }

        // An archive page has no live row; its address is its setting (I-3304).
        $archive = TypeArchives::at($path);

        return $archive === null || ($type === TypeArchives::TARGET && $archive === $id)
            ? null
            : __('The address :address is used by :holder.', ['address' => $shown, 'holder' => self::describe(TypeArchives::TARGET, $archive)]);
    }

    /**
     * The stored language of a content item or a term; null for the default
     * and for any other kind of target.
     */
    private static function languageOf(string $type, string $id): ?string
    {
        // The kinds of KINDS that have a language; any other, a later release's included, has none.
        $table = match ($type) {
            'content' => 'contents',
            'term' => 'terms',
            default => null,
        };

        $language = $table === null ? null : DB::table($table)->where('id', $id)->value('language');

        return is_string($language) ? $language : null;
    }

    /**
     * A target in words: the page "About", the category "News", the archive
     * page "Films", the author "Jane Doe" with an account or without, or, for a kind a later release
     * adds, something this version doesn't know, with the kind's name.
     */
    public static function describe(string $type, string $id): string
    {
        if ($type === 'content') {
            $row = DB::table('contents')->where('id', $id)->first(['type', 'title']);
            $types = app(ContentTypeRegistry::class);
            $noun = $row !== null && $types->has((string) $row->type) ? mb_strtolower($types->get((string) $row->type)->singular) : 'item';

            return __('the :noun ":name"', ['noun' => $noun, 'name' => (string) ($row->title ?? $id)]);
        }

        if ($type === 'term') {
            $row = DB::table('terms')->where('id', $id)->first(['taxonomy', 'name']);
            $taxonomies = app(TaxonomyRegistry::class);
            $noun = $row !== null && $taxonomies->has((string) $row->taxonomy) ? mb_strtolower($taxonomies->get((string) $row->taxonomy)->singular) : 'term';

            return __('the :noun ":name"', ['noun' => $noun, 'name' => (string) ($row->name ?? $id)]);
        }

        if ($type === TypeArchives::TARGET) {
            $types = app(ContentTypeRegistry::class);

            return __('the archive page ":name"', ['name' => $types->has($id) ? $types->get($id)->plural : $id]);
        }

        // A person with no account, by their own name (I-3366).
        if ($type === 'person') {
            return __('the author ":name"', ['name' => (string) (DB::table('people')->where('id', $id)->whereNull('user_id')->value('name') ?? $id)]);
        }

        // Each kind of KINDS in its own words; any other in the same ones.
        if ($type !== 'user') {
            return __('something this version of Teme doesn’t know (:kind)', ['kind' => $type]);
        }

        $account = self::account($type, $id);

        return __('the author ":name"', ['name' => (string) (($account === null ? null : DB::table('users')->where('id', $account)->value('name')) ?? $id)]);
    }
}
