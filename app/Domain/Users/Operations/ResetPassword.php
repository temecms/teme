<?php

/**
 * Authorised only by a valid reset token. Fortify's reset step calls this
 * while the token still exists, so an API, job or AI tool reaching this
 * Operation without the token is refused.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations;

use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Notifications\PasswordChangedNotice;
use App\Domain\Users\Operations\Input\ResetPasswordInput;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Teme\Contracts\Operations\OperationInput;

final class ResetPassword extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var ResetPasswordInput $input */
        $user = User::query()->find($input->userId);

        if ($user === null || ! PasswordBroker::broker()->tokenExists($user, $input->token)) {
            throw new AuthorizationException('This password reset link is invalid.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ResetPasswordInput $input */
        Validator::make(
            ['password' => $input->password, 'password_confirmation' => $input->passwordConfirmation],
            ['password' => ['required', 'string', Password::defaults(), 'confirmed']],
        )->validate();
    }

    protected function perform(OperationInput $input): null
    {
        /** @var ResetPasswordInput $input */
        $user = User::query()->lockForUpdate()->findOrFail($input->userId);

        $user->password = $input->password;
        // The link went to this address, so completing it proves the mailbox —
        // and accepts a pending invitation.
        $user->email_verified_at ??= Date::now();
        $user->invited_at = null;
        $user->setRememberToken(Str::random(60));
        $user->save();

        // After commit: a rolled-back change must not announce itself.
        DB::afterCommit(fn () => $user->notify(new PasswordChangedNotice));

        return null;
    }
}
