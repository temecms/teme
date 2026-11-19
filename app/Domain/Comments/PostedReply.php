<?php

/**
 * What ReplyToComment did (1.8c′'s D11): the reply, whether it approved the
 * comment it answers, and that comment's writer, for the toast.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments;

final readonly class PostedReply
{
    public function __construct(
        public string $replyId,
        public bool $approvedAnswered,
        public ?string $answeredName,
    ) {}
}
