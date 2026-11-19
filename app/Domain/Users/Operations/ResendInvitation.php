<?php

/**
 * Sends a pending invitation again with a fresh link. Only someone who may
 * manage users, or the operator, can do it, and only while the invitation is
 * still pending.
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
use App\Domain\Users\Notifications\InvitationLink;
use App\Domain\Users\Operations\Concerns\ManagesUsers;
use App\Domain\Users\Operations\Input\ResendInvitationInput;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class ResendInvitation extends BaseOperation
{
    use ManagesUsers;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeUserManagement($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ResendInvitationInput $input */
        $user = User::query()->find($input->userId);

        if ($user === null || $user->invited_at === null) {
            throw ValidationException::withMessages(['user' => __('Only a pending invitation can be sent again.')]);
        }
    }

    /**
     * @return array{email: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ResendInvitationInput $input */
        $user = User::query()->lockForUpdate()->findOrFail($input->userId);
        $token = Password::broker('invitations')->createToken($user);

        app(SecurityLog::class)->record(SecurityEventType::UserInvited, $user->id, context: [
            'resent' => true,
            'by' => $input->actorId() === null ? 'operator' : (int) $input->actorId(),
        ]);

        DB::afterCommit(fn () => $user->notify(new InvitationLink($token, $user->id)));

        return ['email' => $user->email];
    }
}
