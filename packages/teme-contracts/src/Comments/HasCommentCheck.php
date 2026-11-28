<?php

/**
 * A plugin granted comments.check whose manifest fills the comment-check
 * slot: asked about each comment before it is saved, in front of core's
 * moderator (docs/guides/developers/plugins/comment-check.md).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Comments;

/**
 * Judges a comment before it is saved. Returns null for no opinion, which
 * leaves the judgment to core's moderator. Core's block and hold lists still
 * apply after the verdict, and the strictest verdict wins.
 *
 * @since next
 */
interface HasCommentCheck
{
    /**
     * The plugin's verdict on this comment, or null for no opinion.
     *
     * @since next
     */
    public function check(CommentCandidate $comment): ?ModerationVerdict;
}
