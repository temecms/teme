<?php

/**
 * A listing's page as data, for a theme's script: the items of the page the
 * resolver built, and its pagination, with each next and previous address
 * pointing at the data of that page. It draws nothing of its own and asks
 * nothing of the database: everything is in the view model the page itself
 * is drawn from, so the data and the page never differ.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Media\ImageMarkup;
use App\Domain\Site\ViewModels\ImageRef;
use App\Domain\Site\ViewModels\LatestView;
use App\Domain\Site\ViewModels\ListingItem;
use App\Domain\Site\ViewModels\ListingView;
use App\Domain\Site\ViewModels\Person;
use App\Domain\Site\ViewModels\TermLink;

final readonly class ListingData
{
    /** The query parameter and value that ask for a page's data. */
    public const string PARAMETER = 'format';

    public const string FORMAT = 'json';

    /** The image context an item's picture is drawn for, as a card's is. */
    private const string IMAGE_CONTEXT = 'card';

    public function __construct(private ImageMarkup $images) {}

    /**
     * The page's data: the subject (null for the latest posts), its items
     * and its pagination. The latest posts' lead and pinned posts are on
     * the page's first page only and are not in the data; its feed is.
     *
     * @return array{subject: array{kind: string, title: string, url: string|null}|null, items: list<array<string, mixed>>, pagination: array{current: int, total: int|null, previous: string|null, next: string|null}}
     */
    public function of(ListingView|LatestView $page): array
    {
        $listing = $page instanceof LatestView ? $page->feed : $page;

        return [
            'subject' => $listing->subject === null ? null : [
                'kind' => $listing->subject->kind,
                'title' => $listing->subject->title,
                'url' => $listing->subject->url,
            ],
            'items' => array_map($this->item(...), $listing->items),
            'pagination' => [
                'current' => $listing->pagination->current,
                'total' => $listing->pagination->total,
                'previous' => $listing->pagination->previous === null ? null : self::dataAddress($listing->pagination->previous),
                'next' => $listing->pagination->next === null ? null : self::dataAddress($listing->pagination->next),
            ],
        ];
    }

    /**
     * A page's address as the address of its data: the same address with the
     * marker added to its query.
     */
    public static function dataAddress(string $url): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').self::PARAMETER.'='.self::FORMAT;
    }

    /**
     * One item as a script reads it. The picture is the item's own markup,
     * as a card draws it.
     *
     * @return array<string, mixed>
     */
    private function item(ListingItem $item): array
    {
        return [
            'title' => $item->title,
            'url' => $item->url,
            'type' => $item->type,
            'standfirst' => $item->standfirst,
            'kicker' => $item->kicker === null ? null : $this->term($item->kicker),
            'authors' => array_map(fn (Person $person): array => ['name' => $person->name, 'url' => $person->url], $item->authors),
            'published' => $item->published->format(DATE_ATOM),
            'publishedText' => $item->publishedText,
            'image' => $item->image === null ? null : $this->image($item->image),
        ];
    }

    /**
     * @return array{name: string, url: string, taxonomy: string}
     */
    private function term(TermLink $term): array
    {
        return ['name' => $term->name, 'url' => $term->url, 'taxonomy' => $term->taxonomy];
    }

    /**
     * @return array{id: string, alt: string|null, caption: string|null, credit: string|null, html: string}
     */
    private function image(ImageRef $image): array
    {
        return [
            'id' => $image->id,
            'alt' => $image->alt,
            'caption' => $image->caption,
            'credit' => $image->credit,
            'html' => $this->images->render($image->id, self::IMAGE_CONTEXT, false, $image->alt, $image->decorative),
        ];
    }
}
