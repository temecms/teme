<?php

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * A later page of an item's comments, as its template sees it, as `$page`:
 * the item's title, address and
 * kicker, and the thread's page. Page 1 is the item itself, which draws it.
 */
final readonly class CommentsPageView
{
    /**
     * @param  string  $title  the item's title
     * @param  string  $url  the item's address, which "Read the story" links to
     * @param  TermLink|null  $kicker  the item's kicker, as its article shows it
     * @param  CommentsView  $comments  this page of the thread
     */
    public function __construct(
        public string $title,
        public string $url,
        public ?TermLink $kicker,
        public CommentsView $comments,
    ) {}
}
