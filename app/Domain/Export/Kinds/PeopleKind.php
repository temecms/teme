<?php

declare(strict_types=1);

namespace App\Domain\Export\Kinds;

use App\Domain\Export\ExportContext;
use App\Domain\Export\ExportJson;
use App\Domain\Export\ExportKind;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every account with its name, email address (I-2408), roles and state, by ID.
 * Never a password hash, a token, a two-factor value, a preference or a log.
 */
final readonly class PeopleKind implements ExportKind
{
    public function name(): string
    {
        return 'people';
    }

    public function version(): int
    {
        return 1;
    }

    public function description(): string
    {
        return 'Every account, by ID.';
    }

    public function noun(): string
    {
        return 'person|people';
    }

    public function fields(): array
    {
        return [
            'id' => 'The person\'s ID.',
            'name' => 'Their name.',
            'email' => 'Their email address.',
            'slug' => 'The last part of their author page\'s address.',
            'bio' => 'Their biography, or null.',
            'roles' => 'Their roles\' names, such as `editor`.',
            'state' => '`active`, `invited` (they haven\'t accepted their invitation yet) or `suspended`.',
            'created_at' => 'When the account was made.',
        ];
    }

    private const int PAGE = 1000;

    private const string PAGE_SQL = 'SELECT id, name, email, slug, bio, invited_at, suspended_at, created_at FROM users WHERE id > ? ORDER BY id LIMIT ?';

    public function lines(ExportContext $context): iterable
    {
        $page = $context->pageSize(self::PAGE);
        $after = 0;

        do {
            $rows = DB::select(self::PAGE_SQL, [$after, $page]);

            if ($rows === []) {
                return;
            }

            $roles = $this->roles(array_map(fn (object $row): int => (int) $row->id, $rows));

            foreach ($rows as $row) {
                $id = (int) $row->id;

                yield [
                    'id' => $id,
                    'name' => (string) $row->name,
                    'email' => (string) $row->email,
                    'slug' => (string) $row->slug,
                    'bio' => $row->bio === null ? null : (string) $row->bio,
                    'roles' => $roles[$id] ?? [],
                    'state' => $row->suspended_at !== null ? 'suspended' : ($row->invited_at !== null ? 'invited' : 'active'),
                    'created_at' => ExportJson::time($row->created_at),
                ];
            }

            $after = (int) $rows[array_key_last($rows)]->id;
        } while (count($rows) === $page);
    }

    /**
     * @param  list<int>  $ids
     *
     * @return array<int, list<string>>
     */
    private function roles(array $ids): array
    {
        $roles = [];

        $rows = DB::table('model_has_roles as h')->join('roles as r', 'r.id', '=', 'h.role_id')
            ->where('h.model_type', (new User)->getMorphClass())->whereIn('h.model_id', $ids)
            ->orderBy('h.model_id')->orderBy('r.name')
            ->get(['h.model_id', 'r.name']);

        foreach ($rows as $row) {
            $roles[(int) $row->model_id][] = (string) $row->name;
        }

        return $roles;
    }
}
