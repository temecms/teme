<?php

/**
 * One of a browser's own comments, as core's script puts it in place: its ID,
 * the comment it's stored under, and what the theme draws: the comment itself,
 * or, for a reply, the comment it's stored under holding it as its only reply.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * One of a browser's own comments, as core's script puts it in place: its ID, the comment it's stored under, and what the theme draws: the
 * comment itself, or, for a reply, the comment it's stored under holding it
 * as its only reply.
 */
final readonly class OwnComment
{
    /**
     * @param  string  $id  the comment's ID
     * @param  string|null  $parentId  the comment it is stored under, or null for none
     * @param  CommentView  $place  what the theme draws for it
     */
    public function __construct(
        public string $id,
        public ?string $parentId,
        public CommentView $place,
    ) {}
}
