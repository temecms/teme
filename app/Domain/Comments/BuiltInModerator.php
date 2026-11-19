<?php

/**
 * Core's comment moderator (docs/studies/comments.md §3.4, step 4): the block
 * list sends to spam; the hold list, links and "hold every comment" hold; then
 * a returning writer is approved and a first comment held once, or, with that
 * option off, whatever passed is approved. A plugin binds its own instead.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments;

use Teme\Contracts\Comments\CommentCandidate;
use Teme\Contracts\Comments\CommentModerator;
use Teme\Contracts\Comments\ModerationVerdict;

final class BuiltInModerator implements CommentModerator
{
    /** The fields a list line is looked for in, in order, with their names in a reason. */
    public const array FIELDS = [
        'text' => 'the comment',
        'name' => 'the name',
        'email' => 'the email address',
        'website' => 'the website',
        'ip' => 'the IP address',
        'browser' => 'the browser',
    ];

    public function name(): string
    {
        return 'Teme';
    }

    public function judge(CommentCandidate $comment): ModerationVerdict
    {
        $settings = DiscussionSettings::current();

        if (($found = self::found($settings->blockList, $comment)) !== null) {
            return new ModerationVerdict(ModerationVerdict::SPAM, "Sent to spam: the block list has “{$found['match']}”, found in ".self::FIELDS[$found['field']].'.', ['rule' => 'block-list', ...$found]);
        }

        if (($found = self::found($settings->holdList, $comment)) !== null) {
            return new ModerationVerdict(ModerationVerdict::PENDING, "Held: the hold list has “{$found['match']}”, found in ".self::FIELDS[$found['field']].'.', ['rule' => 'hold-list', ...$found]);
        }

        if ($settings->holdLinks > 0 && $comment->links >= $settings->holdLinks) {
            return new ModerationVerdict(ModerationVerdict::PENDING, "Held: it has {$comment->links} links.", ['rule' => 'links', 'links' => $comment->links]);
        }

        if ($settings->holdAll) {
            return new ModerationVerdict(ModerationVerdict::PENDING, 'Held: every comment waits for approval.', ['rule' => 'hold-all']);
        }

        if (! $settings->approveReturning) {
            return new ModerationVerdict(ModerationVerdict::APPROVED, 'Approved: it passed every check.', ['rule' => 'passed']);
        }

        return $comment->returning
            ? new ModerationVerdict(ModerationVerdict::APPROVED, 'Approved: a returning commenter.', ['rule' => 'returning'])
            : new ModerationVerdict(ModerationVerdict::PENDING, 'Held: a first comment, or the first from this browser.', ['rule' => 'first-comment']);
    }

    /**
     * The first line of the list found in any of the comment's fields,
     * ignoring case, and the field it was found in, or null.
     *
     * @param  list<string>  $lines
     *
     * @return array{match: string, field: string}|null
     */
    private static function found(array $lines, CommentCandidate $comment): ?array
    {
        $fields = [
            'text' => $comment->text,
            'name' => $comment->authorName,
            'email' => $comment->authorEmail,
            'website' => $comment->authorUrl,
            'ip' => $comment->ip,
            'browser' => $comment->userAgent,
        ];

        foreach ($lines as $line) {
            foreach ($fields as $field => $value) {
                if ($value !== null && $value !== '' && mb_stripos($value, $line) !== false) {
                    return ['match' => $line, 'field' => $field];
                }
            }
        }

        return null;
    }
}
