<?php

declare(strict_types=1);

namespace Teme\Contracts\Import;

/**
 * One record a plugin receives from the WordPress site an import reads:
 * plain values only, never a WordPress or Laravel object.
 *
 * @since 0.9.0
 */
final readonly class WordPressRecord
{
    /**
     * A post of a declared post type.
     *
     * @since 0.9.0
     */
    public const string POST = 'post';

    /**
     * A person's declared meta.
     *
     * @since 0.9.0
     */
    public const string USER = 'user';

    /**
     * A term's declared meta.
     *
     * @since 0.9.0
     */
    public const string TERM = 'term';

    /**
     * @param  string  $kind  one of POST, USER or TERM
     * @param  int  $sourceId  WordPress's own ID for the post, person or term
     * @param  string|null  $type  the post type or the taxonomy; null for a person
     * @param  array<string, mixed>  $fields  a post's guid, title, content, status, date_gmt, author and parent; empty for a person or a term
     * @param  array<string, list<mixed>>  $meta  only the declared keys, each with its values decoded, null for one that held a PHP object
     *
     * @since 0.9.0
     */
    public function __construct(
        public string $kind,
        public int $sourceId,
        public ?string $type,
        public array $fields,
        public array $meta,
    ) {}
}
