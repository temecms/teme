<?php

declare(strict_types=1);

namespace Teme\Contracts\Content;

/**
 * A content item as a hook tells of it: plain values read after the change
 * was saved, never the item itself. Each language version of an article is
 * its own item, with its own ID and address; a hook tells of one version.
 *
 * @since 0.5.0
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
     *
     * @since 0.5.0
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $title,
        public string $status,
        public ?string $url,
        public string $language,
    ) {}
}
