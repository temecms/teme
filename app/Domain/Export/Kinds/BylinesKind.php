<?php

/**
 * Every person a byline names, with an account or without, as pages show
 * them (I-3366): a person with no account by their own name, address and
 * biography, an account's person by the account's. Never an email.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export\Kinds;

use App\Domain\Export\ExportContext;
use App\Domain\Export\ExportKind;
use Illuminate\Support\Facades\DB;

final readonly class BylinesKind implements ExportKind
{
    public function name(): string
    {
        return 'bylines';
    }

    public function version(): int
    {
        return 1;
    }

    public function description(): string
    {
        return 'Every person a byline names, with an account or without, by ID. An item with no bylines credits its owner, `author_id` in `contents`.';
    }

    public function noun(): string
    {
        return 'person|people';
    }

    public function fields(): array
    {
        return [
            'id' => 'The person\'s ID, a lowercase ULID.',
            'account_id' => 'The ID of the account this person is, from `people`, or null.',
            'name' => 'Their name, as pages show it.',
            'slug' => 'The last part of their author page\'s address.',
            'bio' => 'Their biography, or null.',
        ];
    }

    private const int PAGE = 1000;

    // An account's person shows the account's name, address and biography (I-3366).
    private const string PAGE_SQL = <<<'SQL'
        SELECT p.id, p.user_id,
               CASE WHEN p.user_id IS NULL THEN p.name ELSE u.name END AS name,
               CASE WHEN p.user_id IS NULL THEN p.slug ELSE u.slug END AS slug,
               CASE WHEN p.user_id IS NULL THEN p.bio ELSE u.bio END AS bio
        FROM people p LEFT JOIN users u ON u.id = p.user_id
        WHERE p.id > ? ORDER BY p.id LIMIT ?
        SQL;

    public function lines(ExportContext $context): iterable
    {
        $page = $context->pageSize(self::PAGE);
        $after = '';

        do {
            $rows = DB::select(self::PAGE_SQL, [$after, $page]);

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                yield [
                    'id' => (string) $row->id,
                    'account_id' => $row->user_id === null ? null : (int) $row->user_id,
                    'name' => (string) $row->name,
                    'slug' => (string) $row->slug,
                    'bio' => $row->bio === null ? null : (string) $row->bio,
                ];
            }

            $after = (string) $rows[array_key_last($rows)]->id;
        } while (count($rows) === $page);
    }
}
