<?php

declare(strict_types=1);

namespace App\Domain\Users\Queries;

use App\Models\User;

/**
 * The people content can be attributed to, for author pickers and filters.
 */
final class AuthorOptions
{
    /**
     * Everyone who may write content, by name — staff, so at most 500.
     *
     * @return list<array{value: int, label: string}>
     */
    public function all(): array
    {
        return User::permission('content.create')
            ->whereNull('suspended_at')
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['value' => (int) $user->id, 'label' => $user->name])
            ->values()
            ->all();
    }
}
