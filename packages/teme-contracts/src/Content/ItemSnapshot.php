<?php

declare(strict_types=1);

namespace Teme\Contracts\Content;

/**
 * One item's working revision: its parts by handle and slug, never a model.
 *
 * @since 0.9.0
 */
final readonly class ItemSnapshot
{
    /**
     * @param  string  $revisionId  the working revision's ID
     * @param  string  $slug  the last part of its address
     * @param  array<string, mixed>  $body  the Tiptap document
     * @param  array<string, mixed>  $fields  by field handle
     * @param  array<string, list<string>>  $terms  term slugs by taxonomy
     * @param  list<string>  $credits  account IDs the item credits, in order; empty when it credits its owner
     * @param  list<ItemRef>  $translations  the item's other language versions
     *
     * @since 0.9.0
     */
    public function __construct(
        public ItemRef $item,
        public string $revisionId,
        public string $slug,
        public array $body,
        public array $fields,
        public array $terms,
        public array $credits,
        public array $translations,
    ) {}
}
