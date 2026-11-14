<?php

declare(strict_types=1);

namespace App\Domain\People\Queries;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\People\Credit;
use Illuminate\Support\Facades\DB;

/**
 * How a person is shown (I-3366): an account from its own name, address
 * slug and biography, with or without a person row; a person with no
 * account from theirs. Every public reader of a byline asks here or in
 * Credits, never the users table.
 */
final class People
{
    /**
     * Each credit as its author page shows it, keyed by Credit::key(), in
     * one statement: its kind and ID as the credit names them, its name,
     * slug, biography (null when blank) and the stored path of its author
     * page, or null when it has none. A credit that names no one is left
     * out. Each one shown is tagged `person:{id}` on the page being drawn.
     *
     * @param  list<Credit>  $credits
     *
     * @return array<string, object{kind: string, id: string, name: string, slug: string, bio: string|null, path: string|null}>
     */
    public function shown(array $credits): array
    {
        $accounts = [];
        $guests = [];

        foreach ($credits as $credit) {
            if ($credit->kind === Credit::ACCOUNT) {
                $accounts[(int) $credit->id] = (int) $credit->id;
            } else {
                $guests[$credit->id] = $credit->id;
            }
        }

        $branches = [];
        $bindings = [];

        if ($accounts !== []) {
            $branches[] = "SELECT 'user' AS kind, u.id::text AS id, u.name, u.slug, u.bio, a.path"
                .' FROM users u'
                ." LEFT JOIN paths a ON a.target_type = 'user' AND a.target_id = u.id::text AND a.is_current"
                .' WHERE u.id IN ('.self::placeholders($accounts).')';
            $bindings = [...$bindings, ...array_values($accounts)];
        }

        if ($guests !== []) {
            $branches[] = "SELECT 'person' AS kind, p.id::text AS id, p.name, p.slug, p.bio, a.path"
                .' FROM people p'
                ." LEFT JOIN paths a ON a.target_type = 'person' AND a.target_id = p.id::text AND a.is_current"
                .' WHERE p.id IN ('.self::placeholders($guests).') AND p.user_id IS NULL';
            $bindings = [...$bindings, ...array_values($guests)];
        }

        if ($branches === []) {
            return [];
        }

        $shown = [];

        foreach (DB::select(implode(' UNION ALL ', $branches), $bindings) as $row) {
            $person = self::row($row);
            $shown[$person->kind.':'.$person->id] = $person;
        }

        if ($shown !== []) {
            app(RenderRecord::class)->tag(...array_map(fn (object $person): string => PageTags::person($person->id), array_values($shown)));
        }

        return $shown;
    }

    /**
     * The ID of the account's person, or null while no byline has named the
     * account.
     */
    public function personOf(int $userId): ?string
    {
        $id = DB::table('people')->where('user_id', $userId)->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * The credit whose author page has this slug: an account by its own
     * slug, or a person with no account by theirs; null for no one. Two
     * index lookups, the first found.
     */
    public function bySlug(string $slug): ?Credit
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT f.kind, f.id FROM (
                (SELECT 'user' AS kind, u.id::text AS id FROM users u WHERE u.slug = ? LIMIT 1)
                UNION ALL
                (SELECT 'person', p.id::text FROM people p WHERE p.slug = ? AND p.user_id IS NULL LIMIT 1)
            ) f
            LIMIT 1
            SQL, [$slug, $slug]);

        if ($row === null) {
            return null;
        }

        return $row->kind === Credit::ACCOUNT ? Credit::account((int) $row->id) : Credit::person((string) $row->id);
    }

    /**
     * A row of shown() as callers read it: the kind and ID as text, the
     * biography trimmed, or null when blank.
     *
     * @return object{kind: string, id: string, name: string, slug: string, bio: string|null, path: string|null}
     */
    public static function row(object $row): object
    {
        $bio = is_string($row->bio ?? null) && trim($row->bio) !== '' ? trim($row->bio) : null;

        return (object) [
            'kind' => (string) $row->kind,
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'slug' => (string) $row->slug,
            'bio' => $bio,
            'path' => $row->path === null ? null : (string) $row->path,
        ];
    }

    /**
     * One placeholder per value, as whereIn writes them.
     *
     * @param  array<array-key, mixed>  $values
     */
    public static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
