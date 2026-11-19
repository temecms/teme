<?php

/**
 * The rules the user-management Operations share: who may manage users, that
 * nobody acts on themselves, and that the last active administrator is never
 * removed.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations\Concerns;

use App\Domain\Support\Security\Operator;
use App\Domain\Users\Roles;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

trait ManagesUsers
{
    /**
     * A person holding users.manage, or the operator at the shell.
     */
    private function authorizeUserManagement(?string $actorId): void
    {
        if ($actorId === null) {
            if (Operator::isPresent()) {
                return;
            }

            throw new AuthorizationException('Not permitted to manage users.');
        }

        $actor = User::query()->find($actorId);

        if ($actor === null || ! $actor->can('users.manage')) {
            throw new AuthorizationException('Not permitted to manage users.');
        }
    }

    private function refuseSelf(?string $actorId, string $userId, string $message): void
    {
        if ($actorId !== null && $actorId === $userId) {
            throw ValidationException::withMessages(['user' => $message]);
        }
    }

    /**
     * Call inside perform(), before demoting, suspending or deleting $user.
     * Locks every active administrator, so two administrators removing each
     * other at once cannot both succeed.
     */
    private function ensureAnotherAdministratorRemains(User $user): void
    {
        if (! $user->hasRole(Roles::ADMINISTRATOR)) {
            return;
        }

        $others = User::role(Roles::ADMINISTRATOR)
            ->whereNull('suspended_at')
            ->whereNull('invited_at')
            ->whereKeyNot($user->id)
            ->lockForUpdate()
            ->pluck('id');

        if ($others->isEmpty()) {
            throw ValidationException::withMessages([
                'user' => __('This is the last active administrator. Make someone else an administrator first.'),
            ]);
        }
    }
}
