<?php

declare(strict_types=1);

namespace App\Domain\Users\Operations;

use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Operations\Input\UpgradePasswordHashInput;
use App\Domain\Users\Security\PasswordHashFormat;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Replaces the stored hash of your password with Argon2id at the configured
 * costs, after you have signed in with it (I-124): a hash imported from
 * WordPress, an older bcrypt one, or Argon2id at other costs. It checks the
 * password itself and writes nothing unless it matches; the password stays
 * the same, so sessions and the remember token are kept.
 */
final class UpgradePasswordHash extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var UpgradePasswordHashInput $input */
        if ($input->actorId() === null || $input->actorId() !== $input->userId) {
            throw new AuthorizationException('Only the person signing in may upgrade their password hash.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UpgradePasswordHashInput $input */
        if ($input->password === '') {
            throw ValidationException::withMessages(['password' => __('The password is incorrect.')]);
        }
    }

    /**
     * True when the hash was replaced; false when there was nothing to
     * replace, or the password doesn't match the hash stored now. The row is
     * locked before anything is read, so a reset or a change since the
     * sign-in wins.
     */
    protected function perform(OperationInput $input): bool
    {
        /** @var UpgradePasswordHashInput $input */
        $user = User::query()->lockForUpdate()->find($input->userId);

        if ($user === null) {
            return false;
        }

        $stored = (string) $user->password;
        $from = PasswordHashFormat::detect($stored);

        if ($from === null || ! Hash::needsRehash($stored) || ! Hash::check($input->password, $stored)) {
            return false;
        }

        // A WordPress password is kept as WordPress kept it, without the
        // spaces around it (I-124).
        $password = match ($from) {
            PasswordHashFormat::Md5, PasswordHashFormat::Phpass, PasswordHashFormat::WordPressBcrypt => trim($input->password),
            PasswordHashFormat::Bcrypt, PasswordHashFormat::Argon2id => $input->password,
        };

        // Hashed here, not by the model's cast, which keeps a value that
        // already looks like a hash as it is.
        $user->password = Hash::make($password);
        $user->save();

        app(SecurityLog::class)->record(SecurityEventType::PasswordHashUpgraded, $user->id, context: ['from' => $from->value]);

        return true;
    }
}
