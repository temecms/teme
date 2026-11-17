<?php

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

use DateTimeImmutable;

/**
 * One article as its template sees it, as `$page`, from its
 * published revision, or the revision a preview shows. The template
 * computes none of it: the dates to show, the reading time, the lead image
 * and the related posts are all here.
 */
final readonly class ArticleView
{
    /**
     * @param  list<Person>  $authors
     * @param  list<TermLink>  $tags  the terms of the type's flat taxonomies
     * @param  list<array{item: ListingItem, reason: string}>  $related
     * @param  CommentsView|null  $comments  the item's thread, or null while comments are off, the item is Hidden, its type takes none, or it's a preview
     * @param  array<string, mixed>  $fields  that revision's stored fields, by field key
     * @param  list<Crumb>|null  $breadcrumbs  the trail to it, when the active theme draws one
     * @param  array<string, FieldView>  $fieldViews  every field of the nine types the item's type has, core's and the site's own, as a template draws it, by handle, in the type's order
     * @param  ItemLink|null  $parent  the item it sits under, while readers can see it; null at the top, for a type that doesn't nest, and while its parent is hidden
     * @param  list<ItemLink>  $ancestors  the items above it that readers can see, the top first; the trail is made from them
     * @param  list<ItemLink>  $children  the published items under it, in their order, at most 100
     */
    public function __construct(
        /** The item's ID. */
        public string $id,
        /** The item's content type key. */
        public string $type,
        /** The item's title. */
        public string $title,
        /** The item's address. */
        public string $url,
        /** The page template the item chose, or null for the theme's default. */
        public ?string $template,
        /** Only an excerpt someone wrote; never generated. */
        public ?string $standfirst,
        /** Its primary category: the chosen one while it is ticked, else the first in the type's first hierarchical taxonomy. */
        public ?TermLink $kicker,
        /** The people the item credits, its bylines, in order, the main one first; none when it credits no one. A person need not have an account here. */
        public array $authors,
        /** In the site's time zone. */
        public DateTimeImmutable $published,
        /** Only when the revision shown is at least a day newer than the first publication. */
        public ?DateTimeImmutable $updated,
        /** The publication date as words, in the item's language. */
        public string $publishedText,
        /** The updated date as words, in the site's language; null when updated is. */
        public ?string $updatedText,
        /** The minutes the body takes to read, at 238 words a minute. */
        public int $readingMinutes,
        /** The lead image, or null when there is none. */
        public ?ImageRef $lead,
        /** Whether the lead is the featured image, which the template draws, or an image card in the body. */
        public bool $leadIsFeatured,
        /** The body as HTML, drawn by core. */
        public string $bodyHtml,
        public array $tags,
        /** Up to three related posts, each with the reason it is shown; empty when nothing is shared. */
        public array $related,
        public ?CommentsView $comments = null,
        /** The revision shown: the published one, or the one a preview shares. */
        public string $revisionId = '',
        /** The revision's stored values by field key, which core's head reads; a template reads `field()` instead. */
        public array $fields = [],
        /** The media of the first image card at the top level of its body, or null. */
        public ?string $firstImageId = null,
        /** That revision's word count, as stored. */
        public int $wordCount = 0,
        /** When that revision was saved, in the site's time zone. */
        public ?DateTimeImmutable $revised = null,
        public ?array $breadcrumbs = null,
        public array $fieldViews = [],
        public ?ItemLink $parent = null,
        public array $ancestors = [],
        public array $children = [],
    ) {}

    /**
     * One of this type's fields by its handle, as a FieldView that prints as
     * its text, with the type's default when nothing is stored; null for a
     * handle the type doesn't have, because one template may serve several
     * types. Nothing is read here.
     */
    public function field(string $handle): ?FieldView
    {
        return $this->fieldViews[$handle] ?? null;
    }

    /**
     * The fields to list `after` or `before` the article, or with no place
     * both, in order: those the site set to show there that hold something.
     * Nothing is read here.
     *
     * @return list<FieldView>
     */
    public function shownFields(?string $where = null): array
    {
        return array_values(array_filter(
            $this->fieldViews,
            fn (FieldView $field): bool => ($where === null ? in_array($field->show, ['after', 'before'], true) : $field->show === $where) && ! $field->empty,
        ));
    }
}
