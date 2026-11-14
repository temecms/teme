<?php

declare(strict_types=1);

namespace App\Domain\Comments;

/**
 * What EditComment did (1.8c′'s D9): the comment, the name it's saved under,
 * for the toast, and whether its formatting went with a change of words.
 */
final readonly class EditedComment
{
    public function __construct(
        public string $commentId,
        public string $authorName,
        public bool $madePlain,
    ) {}
}
