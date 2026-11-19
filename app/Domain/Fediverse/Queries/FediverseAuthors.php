<?php

/**
 * The people who could have a fediverse account of their own: everyone an
 * item can credit, by the name and author slug their author page shows,
 * whether or not they have an account.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FediverseAuthors
{
    /**
     * One person by ID, with the name and slug their author page shows; null for no one.
     *
     * @return object{id: string, name: string, slug: string}|null
     */
    public function find(string $personId): ?object
    {
        $row = $this->query()->where('p.id', $personId)->first();

        return $row === null ? null : self::row($row);
    }

    /**
     * Every person, by name.
     *
     * @return list<object{id: string, name: string, slug: string}>
     */
    public function all(): array
    {
        return $this->query()->orderByRaw('lower(coalesce(u.name, p.name))')->get()->map(fn (object $row): object => self::row($row))->values()->all();
    }

    private function query(): Builder
    {
        return DB::table('people as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->select('p.id')
            ->selectRaw('coalesce(u.name, p.name) as name, coalesce(u.slug, p.slug) as slug');
    }

    /**
     * @return object{id: string, name: string, slug: string}
     */
    private static function row(object $row): object
    {
        return (object) ['id' => (string) $row->id, 'name' => (string) $row->name, 'slug' => (string) $row->slug];
    }
}
