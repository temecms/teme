<?php

/**
 * What a feed is of: the whole site, a term, an author or a type's archive page, with the name, the stored path of its page, the facts the builder reads and the language its items are in (I-3360).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds;

use App\Domain\People\Credit;

final readonly class FeedSubject
{
    public const string SITE = 'site';

    public const string TERM = 'term';

    public const string AUTHOR = 'author';

    public const string TYPE = 'type';

    /**
     * @param  string  $kind  SITE, TERM, AUTHOR or TYPE
     * @param  string  $name  the term's or author's name; empty for the site
     * @param  string  $path  the stored path of its page, after its language's prefix; empty for the default language's site feed
     * @param  string|null  $description  the term's description or the author's bio
     * @param  string|null  $taxonomy  a term's taxonomy
     * @param  string|null  $termId  a term's ID
     * @param  Credit|null  $credit  an author: an account, or a person with no account (I-3366)
     * @param  string|null  $type  a type's key, for its archive page's feed
     * @param  string|null  $language  the language of its items, as stored: null for the site's default
     */
    public function __construct(
        public string $kind,
        public string $name,
        public string $path,
        public ?string $description,
        public ?string $taxonomy,
        public ?string $termId,
        public ?Credit $credit,
        public ?string $type = null,
        public ?string $language = null,
    ) {}

    /**
     * The site's own feed, in the default language or, at its prefix, in another.
     */
    public static function site(?string $language = null, string $prefix = ''): self
    {
        return new self(self::SITE, '', $prefix, null, null, null, null, null, $language);
    }
}
