<?php

/**
 * The comment emails' switches and their dispatch (1.8d's D12, D16): each
 * kind, whether the site sends an email at all, and its
 * job, queued after the outermost commit, so a change rolled back emails no
 * one. The job reads everything again when it runs.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments;

use App\Domain\Comments\Jobs\SendCommentApprovedEmail;
use App\Domain\Comments\Jobs\SendCommentWaitingEmails;
use App\Domain\Comments\Models\Comment;
use App\Domain\Settings\Modules;

final class CommentEmails
{
    /** The kind of the moderators' email (E7). */
    public const string WAITING = 'comments.waiting';

    /** The kind of the author's email (E7). */
    public const string APPROVED = 'comments.approved';

    /**
     * Whether the site sends this email now: comments on, and its Discussion
     * setting on.
     */
    public static function sends(string $email): bool
    {
        if (! Modules::on(Modules::COMMENTS)) {
            return false;
        }

        $settings = DiscussionSettings::current();

        return $email === self::WAITING ? $settings->emailModerators : $settings->emailAuthors;
    }

    /**
     * Queues the moderators' email for a comment that arrived waiting, from
     * $actorId's action (a signed-in writer, or null for a guest).
     */
    public static function waiting(Comment $comment, ?string $actorId): void
    {
        if (self::sends(self::WAITING)) {
            SendCommentWaitingEmails::dispatch($comment->id, $actorId)->afterCommit();
        }
    }

    /**
     * Queues the author's email for a comment now approved, from $actorId's
     * action (the moderator, the replier, or the signed-in writer).
     */
    public static function approved(Comment $comment, ?string $actorId): void
    {
        if (self::sends(self::APPROVED)) {
            SendCommentApprovedEmail::dispatch($comment->id, $actorId)->afterCommit();
        }
    }
}
