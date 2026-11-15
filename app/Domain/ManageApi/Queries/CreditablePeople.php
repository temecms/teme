<?php

declare(strict_types=1);

namespace App\Domain\ManageApi\Queries;

use App\Models\User;

/**
 * The people an item may be credited to, as the management API lists them:
 * the editor's author choices, everyone active who may write content.
 */
final class CreditablePeople
{
    /**
     * Returns each person's ID, name and slug, by name, at most 500.
     *
     * @return list<array{id: int, name: string, slug: string}>
     */
    public function all(): array
    {
        return User::permission('content.create')
            ->whereNull('suspended_at')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'name', 'slug'])
            ->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name, 'slug' => (string) $user->slug])
            ->values()
            ->all();
    }

    /**
     * Whether a person by this ID may be credited.
     */
    public function has(int $id): bool
    {
        return User::permission('content.create')->whereNull('suspended_at')->whereKey($id)->exists();
    }
}
