<?php

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * What a listing is about: a term, an author, a date, the latest posts at
 * the posts page (`posts`), or a search, with its title, a description and
 * an image; a term's also with its taxonomy, its parent and its children.
 */
final readonly class Subject
{
    /**
     * @param  string  $kind  term, author, date, type, posts or search
     * @param  string  $title  the subject's heading
     * @param  string|null  $description  its description, or null
     * @param  ImageRef|null  $image  its image, or null
     * @param  string|null  $url  the subject's own address, site-relative: a term's, an author's or a date's first page
     * @param  string|null  $taxonomy  a term's taxonomy key; null for any other subject
     * @param  TermLink|null  $parent  a term's parent in a hierarchical taxonomy, whether or not it holds published items; null for a top term, a flat taxonomy's and any other subject
     * @param  list<TermLink>  $children  a term's children in a hierarchical taxonomy that hold at least one published item, by name, at most 100; none for any other subject
     */
    public function __construct(
        public string $kind,
        public string $title,
        public ?string $description = null,
        public ?ImageRef $image = null,
        public ?string $url = null,
        public ?string $taxonomy = null,
        public ?TermLink $parent = null,
        public array $children = [],
    ) {}
}
