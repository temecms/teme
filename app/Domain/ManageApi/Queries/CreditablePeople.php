<?php

/**
 * The people an item may be credited to, as the management API lists them:
 * the editor's author choices (AuthorOptions), with each person's slug.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\ManageApi\Queries;

use App\Domain\Users\Queries\AuthorOptions;
use App\Models\User;

final readonly class CreditablePeople
{
    public function __construct(private AuthorOptions $authors) {}

    /**
     * Returns each person's ID, name and slug, by name, at most 500.
     *
     * @return list<array{id: int, name: string, slug: string}>
     */
    public function all(): array
    {
        return $this->authors->people()
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
        return $this->authors->people()->whereKey($id)->exists();
    }
}
