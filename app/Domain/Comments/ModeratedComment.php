<?php

/**
 * What ModerateComment did with a comment (1.8c's D9): its ID, its status
 * before and after, read under the Operation's lock, and its writer's name,
 * so the Comments screen's Undo puts it back where it was and its toast
 * names whom it moved.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments;

final readonly class ModeratedComment
{
    public function __construct(
        public string $commentId,
        public string $before,
        public string $after,
        public ?string $authorName,
    ) {}

    /**
     * Whether the comment moved: false when it already had the status asked for.
     */
    public function changed(): bool
    {
        return $this->before !== $this->after;
    }
}
