<?php

declare(strict_types=1);

namespace Teme\Contracts\Content;

use Teme\Contracts\Media\MediaRef;

/**
 * What a plugin asks ContentWriter::create() to make: a draft of this type
 * with this title, body, fields and terms.
 *
 * @since 0.9.0
 */
final readonly class NewItem
{
    /**
     * @param  array<string, mixed>  $body  a Tiptap document (`type: doc`)
     * @param  array<string, mixed>  $fields  by field handle
     * @param  array<string, list<string>>  $terms  term slugs or names by taxonomy
     *
     * @since 0.9.0
     */
    public function __construct(
        public string $type,
        public string $title,
        public array $body,
        public array $fields = [],
        public array $terms = [],
        public ?MediaRef $featuredImage = null,
        public ?string $language = null,
    ) {}
}
