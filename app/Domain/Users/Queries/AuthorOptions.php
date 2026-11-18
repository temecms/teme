<?php

/**
 * The people content can be attributed to, for author pickers and filters.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Queries;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class AuthorOptions
{
    /**
     * Everyone who may write content, by name — staff, so at most 500.
     *
     * @return list<array{value: int, label: string}>
     */
    public function all(): array
    {
        return $this->people()
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['value' => (int) $user->id, 'label' => $user->name])
            ->values()
            ->all();
    }

    /**
     * Returns the query for everyone content can be attributed to: active
     * people who may write content. The management API lists and checks
     * the same people (docs/proposals/management-api.md).
     *
     * @return Builder<User>
     *
     * @since 1.0.0
     */
    public function people(): Builder
    {
        return User::permission('content.create')->whereNull('suspended_at');
    }
}
