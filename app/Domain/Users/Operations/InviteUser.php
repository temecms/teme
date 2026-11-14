<?php

declare(strict_types=1);

namespace App\Domain\Users\Operations;

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Notifications\InvitationLink;
use App\Domain\Users\Operations\Concerns\ManagesUsers;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Roles;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Accounts are invited, never self-registered (I-101), and an administrator
 * never sets or sees another person's password (study G, S11).
 */
final class InviteUser extends BaseOperation implements RequiresElevation
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
        /** @var InviteUserInput $input */
        Validator::make(
            ['name' => trim($input->name), 'email' => Str::lower(trim($input->email)), 'role' => $input->role],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
                'role' => ['required', Rule::in(array_keys(Roles::LABELS))],
            ],
        )->validate();
    }

    /**
     * @return array{userId: int, token: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var InviteUserInput $input */
        $user = new User;
        $user->name = trim($input->name);
        $user->email = Str::lower(trim($input->email));
        $user->password = Str::random(64);
        $user->invited_at = Date::now();
        $user->save();
        $user->assignRole($input->role);

        app(PathProjection::class)->syncUser($user);

        // A "not found" page may be stored at the author address they take (I-263).
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::PERSON, (string) $user->id, Footprints::person($user)));

        $token = Password::broker('invitations')->createToken($user);

        app(SecurityLog::class)->record(SecurityEventType::UserInvited, $user->id, context: [
            'role' => $input->role,
            'by' => $input->actorId() === null ? 'operator' : (int) $input->actorId(),
        ]);

        if ($input->deliver) {
            DB::afterCommit(fn () => $user->notify(new InvitationLink($token, $user->id)));
        }

        return ['userId' => $user->id, 'token' => $token];
    }
}
