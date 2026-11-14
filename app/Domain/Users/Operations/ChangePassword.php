<?php

declare(strict_types=1);

namespace App\Domain\Users\Operations;

use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Notifications\PasswordChangedNotice;
use App\Domain\Users\Operations\Input\ChangePasswordInput;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator as ValidatorInstance;
use Teme\Contracts\Operations\OperationInput;

/**
 * Changes your own password after checking the current one. The new password
 * must follow the password rules, your other sessions end, the change is
 * recorded, and a notice is emailed.
 */
final class ChangePassword extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var ChangePasswordInput $input */
        if ($input->actorId() === null || $input->actorId() !== $input->userId) {
            throw new AuthorizationException('You may only change your own password.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ChangePasswordInput $input */
        $user = User::query()->findOrFail($input->userId);

        Validator::make(
            [
                'current_password' => $input->currentPassword,
                'password' => $input->password,
                'password_confirmation' => $input->passwordConfirmation,
            ],
            [
                'current_password' => ['required', 'string'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ],
        )->after(function (ValidatorInstance $validator) use ($user, $input): void {
            if (! Hash::check($input->currentPassword, $user->password)) {
                $validator->errors()->add('current_password', __('The password is incorrect.'));
            }
        })->validate();
    }

    protected function perform(OperationInput $input): null
    {
        /** @var ChangePasswordInput $input */
        $user = User::query()->lockForUpdate()->findOrFail($input->userId);

        $user->password = $input->password;
        $user->setRememberToken(Str::random(60));
        $user->save();

        app(SecurityLog::class)->record(SecurityEventType::PasswordChanged, $user->id);

        // After commit: a rolled-back change must not announce itself.
        DB::afterCommit(fn () => $user->notify(new PasswordChangedNotice));

        return null;
    }
}
