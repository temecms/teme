<?php

/**
 * An item's thread on one page, as a theme is given it: how many approved
 * comments it has, whether it takes new ones now, this page's conversations
 * with their replies under them, the links between its pages, and the form
 * while it's open.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * An item's thread on one page, as a theme is given it: how many approved comments it has, whether it takes new ones
 * now, this page's conversations with their replies under them, the links
 * between its pages, and the form while it's open. There is none at all
 * while comments are switched off, the item is Hidden or its type takes
 * none, so a theme has nothing to draw.
 */
final readonly class CommentsView
{
    /**
     * @param  int  $count  approved comments, pings included
     * @param  bool  $open  whether it takes new comments now: Open, and within the days the settings allow
     * @param  int  $current  this page's number
     * @param  int  $total  how many pages the thread has, at least 1
     * @param  int  $depth  how deep replies go (thread_depth), for core's script
     * @param  list<CommentView>  $items  this page's conversations, oldest first, each with its replies
     * @param  Pagination  $pagination  previous is the older page, next the newer; page 1 is the item at #comments
     * @param  CommentFormView|null  $form  the form while open
     */
    public function __construct(
        public int $count,
        public bool $open,
        public int $current,
        public int $total,
        public int $depth,
        public array $items,
        public Pagination $pagination,
        public ?CommentFormView $form,
    ) {}
}
