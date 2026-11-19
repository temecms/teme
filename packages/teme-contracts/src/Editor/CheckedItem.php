<?php

/**
 * One version of the item being edited, as the plugin's checks read it: its
 * title, address, document and text, its fields by handle, its terms, the
 * plugin's own values from its Search and social section, and who is looking.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Editor;

/**
 * One version of the item being edited, as the plugin's checks read it: its
 * title, address, document and text, its fields by handle, its terms, the
 * plugin's own values from its Search and social section, and who is
 * looking.
 *
 * @since 1.1.0
 */
final readonly class CheckedItem
{
    /**
     * @param  string  $language  the item's language code
     * @param  string  $slug  the item's slug; empty while it has none
     * @param  array<string, mixed>  $document  the body as a Tiptap document
     * @param  string  $text  the body's words as plain text, cards' included
     * @param  array<string, mixed>  $fields  the type's fields by handle, as stored
     * @param  array<string, list<string>>  $terms  the item's terms' names, by taxonomy key
     * @param  array<string, mixed>  $own  the plugin's own Search and social values, by key
     * @param  list<string>  $permissions  this plugin's own permission ids the person holds
     *
     * @since 1.1.0
     */
    public function __construct(
        public string $itemId,
        public string $revisionId,
        public string $type,
        public string $language,
        public string $title,
        public string $slug,
        public array $document,
        public string $text,
        public array $fields,
        public array $terms,
        public array $own,
        public string $personId,
        public string $locale,
        public array $permissions = [],
    ) {}
}
