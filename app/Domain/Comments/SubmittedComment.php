<?php

declare(strict_types=1);

namespace App\Domain\Comments;

/**
 * What SubmitComment did with a comment: its ID, and its status, approved,
 * pending or spam. The form's answer (1.8b) tells its writer only whether it
 * shows now or waits; spam reads as waiting.
 */
final readonly class SubmittedComment
{
    public function __construct(
        public string $commentId,
        public string $status,
    ) {}

    /**
     * Whether readers see the comment now: it was approved.
     */
    public function published(): bool
    {
        return $this->status === CommentStatus::APPROVED;
    }
}
