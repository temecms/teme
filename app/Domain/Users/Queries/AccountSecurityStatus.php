<?php

declare(strict_types=1);

namespace App\Domain\Users\Queries;

use App\Models\User;
use Laravel\Fortify\Features;

/**
 * What the account pages may know about a person's sign-in security, so that
 * controllers read it through a query like everything else (I-136).
 */
final class AccountSecurityStatus
{
    /**
     * Whether email verification applies on this site, and whether the account
     * has confirmed two-factor sign-in.
     *
     * @return array{mustVerifyEmail: bool, twoFactorEnabled: bool}
     */
    public function for(int|string $userId): array
    {
        $user = User::query()->findOrFail($userId);

        // Every User implements MustVerifyEmail, so an instanceof check could
        // never be false (backlog: plan defect found in 1.2e). Whether
        // verification applies is Fortify's feature switch.
        return [
            'mustVerifyEmail' => Features::enabled(Features::emailVerification()),
            'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
        ];
    }
}
