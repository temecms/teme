<?php

/**
 * A plugin granted comments.check whose manifest fills the comment-check
 * slot: asked about each comment before it is saved, in front of core's
 * moderator (docs/guides/developers/plugins/comment-check.md).
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Comments;

/**
 * Judges a comment before it is saved. Returns null for no opinion, which
 * leaves the judgment to core's moderator. Core's block and hold lists still
 * apply after the verdict, and the strictest verdict wins.
 *
 * @since 1.8.0
 */
interface HasCommentCheck
{
    /**
     * The plugin's verdict on this comment, or null for no opinion.
     *
     * @since 1.8.0
     */
    public function check(CommentCandidate $comment): ?ModerationVerdict;
}
