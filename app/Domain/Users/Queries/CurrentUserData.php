<?php

declare(strict_types=1);

namespace App\Domain\Users\Queries;

use App\Domain\Users\Roles;
use App\Models\User;

/**
 * What the admin is allowed to know about the signed-in user. The whole model
 * never reaches the HTTP layer or the browser.
 */
final class CurrentUserData
{
    public function __construct(private readonly PersonPictures $pictures) {}

    /**
     * The chosen fields of a signed-in person that the browser may know, and
     * what they may do, their first role's label and their picture's address
     * — never the model itself. Null when nobody is signed in.
     *
     * @return array{id: int, name: string, email: string, emailVerified: bool, capabilities: list<string>, role: string|null, picture: string|null}|null
     */
    public function find(int|string|null $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            return null;
        }

        /** @var list<string> $capabilities */
        $capabilities = $user->getAllPermissions()->pluck('name')->sort()->values()->all();

        $role = $user->getRoleNames()->first();

        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'emailVerified' => $user->email_verified_at !== null,
            'capabilities' => $capabilities,
            'role' => $role === null ? null : __(Roles::LABELS[$role] ?? $role),
            'picture' => $this->pictures->of((int) $user->id),
        ];
    }
}
