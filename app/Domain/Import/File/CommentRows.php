<?php

/**
 * An export file's comments in the companion's `comments` shape: reader
 * comments, pingbacks and trackbacks, in the statuses the companion sends.
 * A file has no browser name, so `user_agent` stays empty.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

final class CommentRows
{
    private const array TYPES = ['comment', '', 'pingback', 'trackback'];

    private const array STATUSES = ['0', '1', 'spam', 'trash', 'post-trashed'];

    /**
     * Returns the rows of one item's comments that the companion would send.
     *
     * @param  list<array{fields: array<string, string>, meta: list<array{key: string, value: string}>}>  $comments
     *
     * @return list<array{list: string, id: int, payload: array<string, mixed>}>
     */
    public static function rows(int $postId, array $comments): array
    {
        $rows = [];

        foreach ($comments as $comment) {
            $f = $comment['fields'];
            $id = (int) ($f['comment_id'] ?? 0);
            $type = trim($f['comment_type'] ?? '');
            $status = trim($f['comment_approved'] ?? '');

            if ($id <= 0 || ! in_array($type, self::TYPES, true) || ! in_array($status, self::STATUSES, true)) {
                continue;
            }

            $rows[] = ['list' => ItemRows::COMMENTS, 'id' => $id, 'payload' => [
                'id' => $id,
                'post_id' => $postId,
                'parent' => (int) ($f['comment_parent'] ?? 0),
                'type' => $type === '' ? 'comment' : $type,
                'status' => $status,
                'author_name' => $f['comment_author'] ?? '',
                'author_email' => $f['comment_author_email'] ?? '',
                'author_url' => $f['comment_author_url'] ?? '',
                'author_ip' => $f['comment_author_IP'] ?? '',
                'user_agent' => null,
                'date_gmt' => ContentRows::date($f['comment_date_gmt'] ?? null),
                'content' => $f['comment_content'] ?? '',
                'user_id' => (int) ($f['comment_user_id'] ?? 0),
            ]];
        }

        return $rows;
    }
}
