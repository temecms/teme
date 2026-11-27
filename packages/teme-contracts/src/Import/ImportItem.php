<?php

/**
 * One item a reader hands over, in Teme's neutral shape: its kind, its
 * source ID, its old address, its dates, its status in Teme's words, its
 * content in the source's markup, and its terms and author by source ID.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Import;

/**
 * An item as a reader hands it to core. Core converts its content and
 * writes it through its own Operations, as the person who started the
 * import; the reader writes nothing.
 *
 * Statuses are Teme's: published, draft, private, scheduled, trashed. Dates
 * are UTC, as `Y-m-d H:i:s`, or null. The `fields` hold what a kind needs
 * beyond these: a term's taxonomy, a person's login and email, a media
 * item's URL and mime type, and so on, each named in the guide.
 *
 * @since next
 */
final readonly class ImportItem
{
    /**
     * @param  list<string>  $termIds  the source IDs of the terms the item is in
     * @param  array<string, scalar|array<array-key, mixed>|null>  $fields  what the kind needs beyond these fields
     *
     * @since next
     */
    public function __construct(
        public string $kind,
        public string $id,
        public string $title = '',
        public string $slug = '',
        public string $status = 'published',
        public ?string $publishedAt = null,
        public ?string $modifiedAt = null,
        public ?string $oldAddress = null,
        public string $content = '',
        public string $format = 'html',
        public ?string $parentId = null,
        public ?string $authorId = null,
        public array $termIds = [],
        public array $fields = [],
    ) {}
}
