<?php

declare(strict_types=1);

namespace App\Domain\Comments;

/**
 * What ReplyToComment did (1.8c′'s D11): the reply, whether it approved the
 * comment it answers, and that comment's writer, for the toast.
 */
final readonly class PostedReply
{
    public function __construct(
        public string $replyId,
        public bool $approvedAnswered,
        public ?string $answeredName,
    ) {}
}
