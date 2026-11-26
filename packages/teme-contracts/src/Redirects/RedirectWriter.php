<?php

/**
 * A plugin's door for the site's redirects: add, list and remove its own.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Redirects;

use Teme\Contracts\Content\WriteRefused;

/**
 * A plugin's door for the site's redirects, offered when it was granted
 * redirects. It adds a redirect from a path on the site to another path or
 * an address, or answers 410 gone at a path; it lists the redirects it
 * added, and removes them. It never sees, changes or removes a redirect a
 * person or another plugin made. Each redirect it adds is written as the
 * site and marked on Tools › Redirects as added by the plugin.
 *
 * @since next
 */
interface RedirectWriter
{
    /**
     * Adds a redirect from a path on this site, and returns it.
     *
     * @param  string  $from  the path to redirect from, such as /old-page
     * @param  string  $to  a path on this site or an absolute address, such as https://example.com/new, to send readers to
     * @param  int  $status  301 (moved for good) or 302 (moved for now)
     * @param  string  $note  up to 200 characters shown beside it on Tools › Redirects
     *
     * @throws WriteRefused when the redirect is refused: a status other than 301 or 302, an
     *                      address Teme can't use, a path the site itself uses, one that already has
     *                      a redirect, or one that would make a loop or a chain; nothing was written
     *
     * @since next
     */
    public function add(string $from, string $to, int $status = 301, string $note = ''): RedirectRef;

    /**
     * Makes a path on this site answer 410 gone, and returns the redirect.
     *
     * @param  string  $from  the path that is gone, such as /old-page
     * @param  string  $note  up to 200 characters shown beside it on Tools › Redirects
     *
     * @throws WriteRefused when it is refused, as add() is; nothing was written
     *
     * @since next
     */
    public function gone(string $from, string $note = ''): RedirectRef;

    /**
     * The redirects this plugin added, oldest first.
     *
     * @return list<RedirectRef>
     *
     * @since next
     */
    public function mine(): array;

    /**
     * Removes redirects this plugin added, by ID; an ID that is another's, a
     * person's, or unknown is left alone. Returns how many went.
     *
     * @param  list<string>  $ids
     *
     * @since next
     */
    public function remove(array $ids): int;
}
