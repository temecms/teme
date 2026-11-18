<?php

/**
 * The one moderator that judges each new comment no one approved at once: it
 * is shown the comment and whether its writer is returning, and answers
 * approved, pending or spam, with a reason and the signals it saw, which are
 * stored with the comment and shown to moderators.
 *
 * @package Teme
 * @since   0.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Comments;

/**
 * The one moderator that judges each new comment no one approved at once: it
 * is shown the comment and whether its writer is returning, and answers
 * approved, pending or spam, with a reason and the signals it saw, which are
 * stored with the comment and shown to moderators. Core binds its own; a
 * plugin binds another in its place.
 *
 * @since 0.1.0
 */
interface CommentModerator
{
    /**
     * Who judged, as moderators see it named, such as "Teme".
     *
     * @since 0.1.0
     */
    public function name(): string;

    /**
     * Whether the comment is approved, held or spam, and why.
     *
     * @since 0.1.0
     */
    public function judge(CommentCandidate $comment): ModerationVerdict;
}
