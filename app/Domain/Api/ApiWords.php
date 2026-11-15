<?php

declare(strict_types=1);

namespace App\Domain\Api;

/**
 * Every sentence the API says to whoever calls it: each problem's detail
 * and each refused parameter's, so the words live in one place and a later
 * part reuses them rather than writing its own.
 */
final class ApiWords
{
    /**
     * invalid_parameter's detail.
     */
    public static function invalidParameters(): string
    {
        return __('One or more query parameters can’t be used.');
    }

    /**
     * not_found's detail for an item asked for by ID.
     */
    public static function noItem(string $id): string
    {
        return __('No published item has the ID :id.', ['id' => $id]);
    }

    /**
     * not_found's detail for an item asked for by its address.
     */
    public static function noItemAt(string $path): string
    {
        return __('No published item has the address :path.', ['path' => $path]);
    }

    /**
     * not_found's detail for a term asked for by ID.
     */
    public static function noTerm(string $id): string
    {
        return __('No public term has the ID :id.', ['id' => $id]);
    }

    /**
     * not_found's detail for a media file asked for by ID.
     */
    public static function noFile(string $id): string
    {
        return __('No published item shows a file with the ID :id.', ['id' => $id]);
    }

    /**
     * not_found's detail for any other address under /api, pointing at the API's start.
     */
    public static function nothingAt(string $path): string
    {
        return __('The API has nothing at :path. Start at :root.', ['path' => $path, 'root' => url('/api/v1')]);
    }

    /**
     * api_off's detail.
     */
    public static function off(): string
    {
        return __('This site doesn’t share its content through the API.');
    }

    /**
     * method_not_allowed's detail.
     */
    public static function methodNotAllowed(): string
    {
        return __('The API only reads. Use GET.');
    }

    /**
     * rate_limited's detail.
     */
    public static function rateLimited(int $seconds): string
    {
        return __('Too many requests from your address. Try again in :seconds seconds.', ['seconds' => (string) $seconds]);
    }

    /**
     * unavailable's detail.
     */
    public static function unavailable(): string
    {
        return __('The site is being updated. Try again shortly.');
    }

    /**
     * unavailable's detail while the site waits for its first account (issue 246).
     */
    public static function settingUp(): string
    {
        return __('This site is being set up. Try again later.');
    }

    /**
     * server_error's detail.
     */
    public static function serverError(): string
    {
        return __('Something went wrong on the site. It has been noted.');
    }

    /**
     * http_error's detail.
     */
    public static function httpError(): string
    {
        return __('The request couldn’t be answered.');
    }

    /**
     * A parameter the address doesn't take.
     */
    public static function unknownParameter(string $name): string
    {
        return __(':name isn’t a parameter this address takes.', ['name' => $name]);
    }

    /**
     * A parameter given twice, or as a list.
     */
    public static function repeatedParameter(string $name): string
    {
        return __(':name can be given once, as plain text.', ['name' => $name]);
    }

    /**
     * A value that isn't UTF-8 or holds a control character.
     */
    public static function badText(string $name): string
    {
        return __(':name must be plain text, with no control characters.', ['name' => $name]);
    }

    /**
     * A limit that isn't a whole number from 1 to 100.
     */
    public static function badLimit(): string
    {
        return __('limit must be a whole number from 1 to 100.');
    }

    /**
     * A cursor this list didn't give.
     */
    public static function badAfter(): string
    {
        return __('after must be a cursor this list gave, from links.next.');
    }

    /**
     * A type that isn't a public type's key, naming the ones that are.
     *
     * @param  list<string>  $types
     */
    public static function badType(array $types): string
    {
        return __('type must name public types of this site, separated by commas, at most 10: :types.', ['types' => implode(', ', $types)]);
    }

    /**
     * A term that isn't a public taxonomy's.
     */
    public static function badTerm(): string
    {
        return __('term must be the ID of a term in a public taxonomy.');
    }

    /**
     * published_since or published_before that isn't a date and time with its offset.
     */
    public static function badDate(string $name): string
    {
        return __(':name must be a date and time with its offset, such as 2026-11-10T09:30:00Z.', ['name' => $name]);
    }

    /**
     * A sort the list doesn't offer, or position without a parent.
     */
    public static function badSort(): string
    {
        return __('sort must be published_at, -published_at, or position with parent.');
    }

    /**
     * A parent that isn't an item's ID (I-3363).
     */
    public static function badItemParent(): string
    {
        return __('parent must be the ID of an item.');
    }

    /**
     * A path that isn't an address on this site.
     */
    public static function badPath(): string
    {
        return __('path must be an address on this site, starting with /, without ? or #, at most 2,048 characters.');
    }

    /**
     * A language the site doesn't show readers, naming the ones it does (I-3360).
     *
     * @param  list<string>  $languages
     */
    public static function badLanguage(array $languages): string
    {
        return __('language must name a language this site shows: :languages.', ['languages' => implode(', ', $languages)]);
    }

    /**
     * A taxonomy that isn't a public one, naming the ones that are.
     *
     * @param  list<string>  $taxonomies
     */
    public static function badTaxonomy(array $taxonomies): string
    {
        return __('taxonomy must name a public taxonomy of this site: :taxonomies.', ['taxonomies' => implode(', ', $taxonomies)]);
    }

    /**
     * A parent that isn't a term's ID or none.
     */
    public static function badParent(): string
    {
        return __('parent must be the ID of a term, or none.');
    }

    /**
     * A slug longer than any term's.
     */
    public static function badSlug(): string
    {
        return __('slug must be at most 200 characters.');
    }
}
