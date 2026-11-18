<?php

/**
 * Where a moderation or deletion on the Comments screen answers to (1.8c's
 * D23): the list as the request's page showed it, its view, item, search
 * and cursor, and never its drawer, so a comment deleted from its own drawer
 * never answers 404; nothing else a Referer carries is kept, and the address
 * is the route's, never the Referer's.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Comments;

final class CommentsAddress
{
    /** @var list<string> */
    private const array KEPT = ['view', 'item', 'search', 'after', 'before'];

    public static function back(): string
    {
        $query = [];
        parse_str((string) parse_url(url()->previous(), PHP_URL_QUERY), $query);

        $kept = [];

        foreach (self::KEPT as $key) {
            if (isset($query[$key]) && is_string($query[$key]) && $query[$key] !== '') {
                $kept[$key] = $query[$key];
            }
        }

        return route('comments.index', $kept);
    }
}
