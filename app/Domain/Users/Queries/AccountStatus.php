<?php

/**
 * Answers two questions about a person's account for the admin's middleware:
 * whether it is suspended, and whether their role requires two-factor sign-in
 * that they have not set up.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Queries;

use App\Models\User;
use Teme\Contracts\Settings\SettingsReader;

final class AccountStatus
{
    public function __construct(private readonly SettingsReader $settings) {}

    /**
     * Whether the person's account is suspended.
     */
    public function isSuspended(int|string $userId): bool
    {
        return User::query()->whereKey($userId)->whereNotNull('suspended_at')->exists();
    }

    /**
     * I-115: true when the person's role requires two-factor sign-in and they
     * have not confirmed it.
     */
    public function lacksRequiredTwoFactor(int|string $userId): bool
    {
        $required = $this->settings->stringList('security', 'two_factor_required_roles');

        if ($required === []) {
            return false;
        }

        $user = User::query()->find($userId);

        return $user !== null
            && $user->two_factor_confirmed_at === null
            && $user->hasAnyRole($required);
    }
}
