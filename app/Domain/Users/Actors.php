<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The people Operations act as, each loaded once, with their roles and
 * permissions, while a caller keeps them: the scheduler for its pass, Bulk
 * Edit for its batch (B-200, B-201). Every caller that keeps also forgets, in
 * a finally, and the binding is scoped, so nothing outlives the pass, the
 * batch, the request or the job (I-087). With nobody kept, find() loads its
 * person as every Operation always has.
 */
final class Actors
{
    /** @var array<string, User> */
    private array $kept = [];

    /**
     * Keeps these people until forget(): IDs loaded in one query with their
     * roles and permissions, a person already loaded as they are.
     */
    public function keep(User|string ...$people): void
    {
        $ids = [];

        foreach ($people as $person) {
            if ($person instanceof User) {
                $person->loadMissing('roles', 'permissions');
                $this->kept[(string) $person->getKey()] = $person;
            } else {
                $ids[] = $person;
            }
        }

        $ids = array_values(array_diff(array_unique($ids), array_keys($this->kept)));

        if ($ids === []) {
            return;
        }

        foreach (User::query()->with('roles', 'permissions')->whereKey($ids)->get() as $user) {
            $this->kept[(string) $user->getKey()] = $user;
        }
    }

    /**
     * The person with this ID: one kept, else loaded now; null for null.
     */
    public function find(?string $id): ?User
    {
        if ($id === null) {
            return null;
        }

        return $this->kept[$id] ?? User::query()->find($id);
    }

    /**
     * The person with this ID, as find() gives them, or a
     * ModelNotFoundException, as User::findOrFail() throws.
     */
    public function findOrFail(?string $id): User
    {
        return $this->find($id) ?? throw (new ModelNotFoundException)->setModel(User::class, [$id]);
    }

    /**
     * Keeps nobody: the next pass or batch reads its people afresh.
     */
    public function forget(): void
    {
        $this->kept = [];
    }
}
