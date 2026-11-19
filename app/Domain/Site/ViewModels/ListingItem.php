<?php

/**
 * One item in a listing, from its published revision: its title and address,
 * the excerpt someone wrote, its kicker, its authors, when it was published
 * and that date to show, its featured image, and its fields.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

use DateTimeImmutable;

/**
 * One item in a listing, from its published revision: its title and
 * address, the excerpt someone wrote, its kicker, its authors, when it was
 * published and that date to show, its featured image, and its fields.
 */
final readonly class ListingItem
{
    /**
     * @param  string  $title  the item's published title
     * @param  string  $url  the item's address
     * @param  string|null  $standfirst  the excerpt someone wrote; null for none, and for a protected item
     * @param  TermLink|null  $kicker  the item's kicker, as its article shows it
     * @param  list<Person>  $authors  The people the item credits, its bylines, in order, the main one first; none when it credits no one. A person need not have an account here.
     * @param  DateTimeImmutable  $published  when it was published, in the site's time zone
     * @param  string  $publishedText  the publication date as words, in the item's language
     * @param  ImageRef|null  $image  its featured image; null for none, and for a protected item
     * @param  string  $type  the item's content type key
     * @param  array<string, FieldView>  $fieldViews  its published fields as a template draws them, by handle; none for a protected item
     */
    public function __construct(
        public string $title,
        public string $url,
        public ?string $standfirst,
        public ?TermLink $kicker,
        public array $authors,
        public DateTimeImmutable $published,
        public string $publishedText,
        public ?ImageRef $image,
        public string $type = '',
        public array $fieldViews = [],
    ) {}

    /**
     * One of the item's fields by its handle, as a FieldView that prints as
     * its text; null for a handle its type doesn't have, and for every
     * handle on a protected item's card. Nothing is read here.
     */
    public function field(string $handle): ?FieldView
    {
        return $this->fieldViews[$handle] ?? null;
    }
}
