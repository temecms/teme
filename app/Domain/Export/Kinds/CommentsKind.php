<?php

/**
 * Every comment but spam on an item not in the trash, with its commenter's
 * name, email address and website (I-2408). Never an IP address, a browser,
 * the private edit token or the spam check's verdict.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export\Kinds;

use App\Domain\Comments\CommentStatus;
use App\Domain\Export\ExportContext;
use App\Domain\Export\ExportJson;
use App\Domain\Export\ExportKind;
use Illuminate\Support\Facades\DB;

final readonly class CommentsKind implements ExportKind
{
    public function name(): string
    {
        return 'comments';
    }

    public function version(): int
    {
        return 1;
    }

    public function description(): string
    {
        return 'Every comment on an exported item, but spam, by ID.';
    }

    public function noun(): string
    {
        return 'comment|comments';
    }

    public function fields(): array
    {
        return [
            'id' => 'The comment\'s ID.',
            'content_id' => 'The item it is on.',
            'parent_id' => 'The comment it answers, or null.',
            'comment_kind' => '`comment`, `pingback` or `trackback`.',
            'status' => '`approved`, `pending`, `trash`, `removed` (a removed comment kept so its replies keep their place, with no words or author), or a status a plugin registered.',
            'author_name' => 'The commenter\'s name, or null.',
            'author_email' => 'The commenter\'s email address, or null.',
            'author_url' => 'The commenter\'s website, or null.',
            'author_user_id' => 'The person, when a signed-in person wrote it; otherwise null.',
            'body' => 'Its words, as Tiptap JSON, or null when removed.',
            'legacy_id' => 'Its number on the site it was imported from, or null.',
            'created_at' => 'When it was written.',
            'status_changed_at' => 'When its status last changed.',
        ];
    }

    private const int PAGE = 1000;

    private const string PAGE_SQL = <<<'SQL'
        SELECT m.id, m.content_id, m.parent_id, m.kind, m.status, m.author_name, m.author_email, m.author_url,
               m.author_user_id, m.body, m.legacy_id, m.created_at, m.status_changed_at
        FROM comments m
        JOIN contents c ON c.id = m.content_id AND c.deleted_at IS NULL
        WHERE m.status <> ? AND m.id > ?
        ORDER BY m.id
        LIMIT ?
        SQL;

    public function lines(ExportContext $context): iterable
    {
        $page = $context->pageSize(self::PAGE);
        $after = '';

        do {
            $rows = DB::select(self::PAGE_SQL, [CommentStatus::SPAM, $after, $page]);

            foreach ($rows as $row) {
                yield [
                    'id' => (string) $row->id,
                    'content_id' => (string) $row->content_id,
                    'parent_id' => $row->parent_id === null ? null : (string) $row->parent_id,
                    'comment_kind' => (string) $row->kind,
                    'status' => (string) $row->status,
                    'author_name' => $row->author_name === null ? null : (string) $row->author_name,
                    'author_email' => $row->author_email === null ? null : (string) $row->author_email,
                    'author_url' => $row->author_url === null ? null : (string) $row->author_url,
                    'author_user_id' => $row->author_user_id === null ? null : (int) $row->author_user_id,
                    'body' => ExportJson::decode($row->body),
                    'legacy_id' => $row->legacy_id === null ? null : (int) $row->legacy_id,
                    'created_at' => ExportJson::time($row->created_at),
                    'status_changed_at' => ExportJson::time($row->status_changed_at),
                ];
            }

            if ($rows !== []) {
                $after = (string) $rows[array_key_last($rows)]->id;
            }
        } while (count($rows) === $page);
    }
}
