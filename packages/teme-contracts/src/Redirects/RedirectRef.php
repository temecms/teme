<?php

/**
 * A redirect a plugin added, as the redirect door tells of it: plain values.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Redirects;

/**
 * A redirect a plugin added, as the redirect door tells of it: plain values,
 * never the redirect itself.
 *
 * @since next
 */
final readonly class RedirectRef
{
    /**
     * @param  string  $id  the redirect's ID
     * @param  string  $from  the site path it starts from, such as /old-page
     * @param  string|null  $to  where it sends readers, a site path or an absolute address; null for 410
     * @param  int  $status  what it answers: 301, 302 or 410
     * @param  string  $note  the note it was added with, which may be empty
     * @param  bool  $answering  whether it answers readers now; false while a page already answers its address or its switch is off
     *
     * @since next
     */
    public function __construct(
        public string $id,
        public string $from,
        public ?string $to,
        public int $status,
        public string $note = '',
        public bool $answering = true,
    ) {}
}
