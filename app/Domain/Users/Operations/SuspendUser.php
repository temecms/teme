<?php

/**
 * The everyday way to remove someone's access: reversible, and everything
 * they wrote keeps their name (I-102).
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
use App\Domain\Users\AccountSessions;
use App\Domain\Users\Operations\Concerns\ManagesUsers;
use App\Domain\Users\Operations\Input\SuspendUserInput;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class SuspendUser extends BaseOperation implements RequiresElevation
{
    use ManagesUsers;

    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeUserManagement($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SuspendUserInput $input */
        $this->refuseSelf($input->actorId(), $input->userId, __('You cannot suspend yourself.'));

        Validator::make(['user' => $input->userId], ['user' => ['required', Rule::exists('users', 'id')]])->validate();
    }

    protected function perform(OperationInput $input): null
    {
        /** @var SuspendUserInput $input */
        $user = User::query()->lockForUpdate()->findOrFail($input->userId);

        if ($input->suspended === ($user->suspended_at !== null)) {
            return null;
        }

        if ($input->suspended) {
            $this->ensureAnotherAdministratorRemains($user);
            $user->suspended_at = Date::now();
            $user->save();
            AccountSessions::endAllFor($user);
        } else {
            $user->suspended_at = null;
            $user->save();
        }

        app(SecurityLog::class)->record(
            $input->suspended ? SecurityEventType::UserSuspended : SecurityEventType::UserReactivated,
            $user->id,
            context: ['by' => $input->actorId() === null ? 'operator' : (int) $input->actorId()],
        );

        return null;
    }
}
