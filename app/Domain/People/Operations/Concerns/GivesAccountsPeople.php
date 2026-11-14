<?php

declare(strict_types=1);

namespace App\Domain\People\Operations\Concerns;

use App\Domain\People\Queries\People;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Makes an account's person the first time a byline names it (I-3366):
 * inside an Operation's perform(), after its own row locks, so a person
 * never outlives the transaction that needed it.
 */
trait GivesAccountsPeople
{
    /**
     * Each account's person ID, keyed by account ID, made now for any
     * account that has none: one insert, its rows in ascending account ID
     * so two Operations naming the same accounts wait on each other in one
     * order and never deadlock, then one read.
     *
     * @param  list<int>  $userIds
     *
     * @return array<int, string>
     */
    protected function personIdsFor(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map(intval(...), $userIds)));

        if ($userIds === []) {
            return [];
        }

        sort($userIds);
        $rows = [];
        $bindings = [];

        foreach ($userIds as $userId) {
            $rows[] = '(?, ?, now(), now())';
            $bindings[] = strtolower((string) Str::ulid());
            $bindings[] = $userId;
        }

        DB::insert('INSERT INTO people (id, user_id, created_at, updated_at) VALUES '.implode(', ', $rows).' ON CONFLICT (user_id) DO NOTHING', $bindings);

        $people = [];

        foreach (DB::select('SELECT id, user_id FROM people WHERE user_id IN ('.People::placeholders($userIds).')', $userIds) as $row) {
            $people[(int) $row->user_id] = (string) $row->id;
        }

        return $people;
    }
}
