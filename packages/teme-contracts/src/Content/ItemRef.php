<?php

/**
 * A content item as a hook tells of it: plain values read after the change was
 * saved, never the item itself.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

/**
 * A content item as a hook tells of it: plain values read after the change
 * was saved, never the item itself. Each language version of an article is
 * its own item, with its own ID and address; a hook tells of one version.
 *
 * @since 0.5.0
 * @since 1.0.0 Carries $indexable, whether search engines may list the item's page.
 */
final readonly class ItemRef
{
    /**
     * @param  string  $id  the item's ID, a lower-case ULID
     * @param  string  $type  its content type's key
     * @param  string  $title  its title
     * @param  string  $status  its status. Today one of: draft, review, scheduled or published; later releases may add others, so a plugin treats a value it does not know as it would an unknown status, and never matches the list exhaustively.
     * @param  string|null  $url  its live address as an absolute URL, or null when it has none: a draft, a private or trashed item, or a type with no public address
     * @param  string  $language  The item's language as a BCP 47 tag, such as en-GB. The site's language today; an item's own once a site holds more than one.
     * @param  bool  $indexable  Whether search engines may list its page: false when it has no url, or when its page says noindex (its own "Hide from search engines", its type hidden in Search and social, the whole site discouraged, or a password on it). Worked out by the code that writes the page's robots meta, so the two never disagree. @since 1.0.0
     *
     * @since 0.5.0
     * @since 1.0.0 Takes $indexable.
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $title,
        public string $status,
        public ?string $url,
        public string $language,
        public bool $indexable = true,
    ) {}
}
