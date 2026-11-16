<?php

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * The item a comment form posts to, found by the address the form sends,
 * never its ID: its ID, for SubmitComment, and its title and
 * address, for the pages that answer the form.
 */
final readonly class CommentTarget
{
    /**
     * @param  string  $id  the item's ID
     * @param  string  $title  the item's title
     * @param  string  $url  the item's address
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $url,
    ) {}
}
