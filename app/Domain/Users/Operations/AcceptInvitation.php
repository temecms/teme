<?php

/**
 * Authorised only by a valid invitation token for a pending invitation.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations;

use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Operations\Input\AcceptInvitationInput;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Teme\Contracts\Operations\OperationInput;

final class AcceptInvitation extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var AcceptInvitationInput $input */
        $user = User::query()->where('email', Str::lower(trim($input->email)))->first();

        if ($user === null || $user->invited_at === null || ! PasswordBroker::broker('invitations')->tokenExists($user, $input->token)) {
            throw new AuthorizationException('This invitation link is invalid or has expired.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var AcceptInvitationInput $input */
        Validator::make(
            ['password' => $input->password, 'password_confirmation' => $input->passwordConfirmation],
            ['password' => ['required', 'string', Password::defaults(), 'confirmed']],
        )->validate();
    }

    protected function perform(OperationInput $input): null
    {
        /** @var AcceptInvitationInput $input */
        $user = User::query()->where('email', Str::lower(trim($input->email)))->lockForUpdate()->firstOrFail();

        $user->password = $input->password;
        $user->email_verified_at ??= Date::now();
        $user->invited_at = null;
        $user->setRememberToken(Str::random(60));
        $user->save();

        PasswordBroker::broker('invitations')->deleteToken($user);

        app(SecurityLog::class)->record(SecurityEventType::InvitationAccepted, $user->id);

        return null;
    }
}
