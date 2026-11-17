<?php

declare(strict_types=1);

namespace Teme\Contracts\Content;

use Teme\Contracts\Media\MediaRef;

/**
 * What a plugin asks ContentWriter::change() to save on an item's draft.
 * Each null field leaves that part as it is.
 *
 * @since 1.0.0
 */
final readonly class ItemChanges
{
    /**
     * @param  array<string, mixed>|null  $body  a Tiptap document (`type: doc`), or null to leave it
     * @param  array<string, mixed>|null  $fields  by field handle, or null to leave them
     * @param  array<string, list<string>>|null  $terms  term slugs or names by taxonomy, or null to leave them
     * @param  string|null  $slug  the last part of its address, or null to leave it
     * @param  list<string>|null  $credits  account IDs to credit, in order (empty credits its owner), or null to leave them
     *
     * @since 1.0.0
     */
    public function __construct(
        public ?string $title = null,
        public ?array $body = null,
        public ?array $fields = null,
        public ?array $terms = null,
        public ?MediaRef $featuredImage = null,
        public ?string $slug = null,
        public ?array $credits = null,
    ) {}
}
