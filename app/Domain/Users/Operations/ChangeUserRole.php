<?php

declare(strict_types=1);

namespace App\Domain\Users\Operations;

use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\AccountSessions;
use App\Domain\Users\Operations\Concerns\ManagesUsers;
use App\Domain\Users\Operations\Input\ChangeUserRoleInput;
use App\Domain\Users\Roles;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Changes a person's role to one of the core roles. Only someone who may
 * manage users — or the operator — can do it, it asks for a fresh password
 * confirmation, and it never changes your own role or demotes the last active
 * administrator. The person is signed out everywhere, and it says what it did, for the screen's toast.
 */
final class ChangeUserRole extends BaseOperation implements RequiresElevation
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
        /** @var ChangeUserRoleInput $input */
        $this->refuseSelf($input->actorId(), $input->userId, __('You cannot change your own role. Ask another administrator.'));

        Validator::make(
            ['user' => $input->userId, 'role' => $input->role],
            ['user' => ['required', Rule::exists('users', 'id')], 'role' => ['required', Rule::in(array_keys(Roles::LABELS))]],
        )->validate();
    }

    /**
     * @return array{name: string, role: string, changed: bool, invited: bool}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ChangeUserRoleInput $input */
        $user = User::query()->lockForUpdate()->findOrFail($input->userId);
        $from = $user->getRoleNames()->first();
        $done = ['name' => $user->name, 'role' => $input->role, 'changed' => false, 'invited' => $user->invited_at !== null];

        if ($from === $input->role) {
            return $done;
        }

        if ($input->role !== Roles::ADMINISTRATOR) {
            $this->ensureAnotherAdministratorRemains($user);
        }

        $user->syncRoles([$input->role]);
        AccountSessions::endAllFor($user);

        app(SecurityLog::class)->record(SecurityEventType::RoleChanged, $user->id, context: [
            'from' => $from,
            'to' => $input->role,
            'by' => $input->actorId() === null ? 'operator' : (int) $input->actorId(),
        ]);

        return [...$done, 'changed' => true];
    }
}
