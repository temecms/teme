<?php

/**
 * What a plugin asks ContentWriter::create() to make: a draft of this type
 * with this title, body, fields and terms, and when given its slug, the people
 * it credits and the item it is a translation of.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

use Teme\Contracts\Media\MediaRef;

/**
 * What a plugin asks ContentWriter::create() to make: a draft of this type
 * with this title, body, fields and terms, and when given its slug, the
 * people it credits and the item it is a translation of.
 *
 * @since 1.0.0
 */
final readonly class NewItem
{
    /**
     * @param  array<string, mixed>  $body  a Tiptap document (`type: doc`)
     * @param  array<string, mixed>  $fields  by field handle
     * @param  array<string, list<string>>  $terms  term slugs or names by taxonomy
     * @param  string|null  $slug  the last part of its address; null makes one from the title
     * @param  string|null  $translationOf  an existing item's ID, in another language, this is a version of
     * @param  list<string>  $credits  account IDs to credit, in order; empty credits the acting person
     *
     * @since 1.0.0
     */
    public function __construct(
        public string $type,
        public string $title,
        public array $body,
        public array $fields = [],
        public array $terms = [],
        public ?MediaRef $featuredImage = null,
        public ?string $language = null,
        public ?string $slug = null,
        public ?string $translationOf = null,
        public array $credits = [],
    ) {}
}
