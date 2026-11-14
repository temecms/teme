<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Domain\Users\Operations\Input\ResetPasswordInput;
use App\Domain\Users\Operations\ResetPassword;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * Fortify's hook for finishing a password reset. It hands the reset to the
 * `ResetPassword` Operation, so a password is written in one place only.
 */
final class ResetUserPassword implements ResetsUserPasswords
{
    public function __construct(private readonly ResetPassword $resetPassword) {}

    /**
     * @param  Authenticatable  $user
     * @param  array<string, string>  $input
     */
    public function reset($user, array $input): void
    {
        $this->resetPassword->execute(new ResetPasswordInput(
            userId: (string) $user->getAuthIdentifier(),
            token: (string) ($input['token'] ?? ''),
            password: (string) ($input['password'] ?? ''),
            passwordConfirmation: (string) ($input['password_confirmation'] ?? ''),
        ));
    }
}
