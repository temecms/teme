<?php

declare(strict_types=1);

namespace App\Domain\Users\Operations;

use App\Domain\Cache\Footprints;
use App\Domain\Contents\AuthorSort;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\AccountSessions;
use App\Domain\Users\ImportedHashes;
use App\Domain\Users\Operations\Concerns\ManagesUsers;
use App\Domain\Users\Operations\Input\UpdateImportedUserInput;
use App\Domain\Users\Roles;
use App\Domain\Users\Security\PasswordHashFormat;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * Brings a person an earlier import created up to date with WordPress, for
 * as long as WordPress is still where they sign in from: while their
 * stored hash is one Teme never writes, their name, biography and hash
 * follow WordPress's, and so does a lower role, never a higher one; no
 * role at all suspends them. Once they have signed in here nothing
 * changes; nor does the person running the import (I-340).
 */
final class UpdateImportedUser extends BaseOperation
{
    use AuthorizesImports;
    use ManagesUsers;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UpdateImportedUserInput $input */
        Validator::make(
            ['name' => trim($input->name), 'bio' => $input->bio, 'role' => $input->role],
            ['name' => ['required', 'string', 'max:255'], 'bio' => ['nullable', 'string', 'max:1000'], 'role' => ['nullable', Rule::in(array_keys(Roles::LABELS))]],
        )->validate();

        if ($input->passwordHash !== null && ImportedHashes::usable($input->passwordHash) === null) {
            throw ValidationException::withMessages(['password' => __('Teme can’t check a password stored this way.')]);
        }
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var UpdateImportedUserInput $input */
        $user = User::query()->lockForUpdate()->findOrFail($input->userId);
        $stored = (string) $user->getRawOriginal('password');

        // Under the lock: a sign-in that upgrades the hash, or a reset, wins.
        if ($input->actorId === (string) $user->id || ! in_array(PasswordHashFormat::detect($stored), ImportedHashes::IMPORTED_FORMATS, true)) {
            return false;
        }

        $user->name = trim($input->name);
        $user->bio = $input->bio;
        $renamed = $user->isDirty('name');
        $shown = $renamed || $user->isDirty('bio');
        $user->save();

        // As UpdateProfile does: the lists' author sort follows the name.
        if ($renamed) {
            DB::table('contents')->where('author_id', $user->id)->update(['author_sort' => AuthorSort::of($user->name)]);
        }

        $rehashed = $input->passwordHash !== null && ! hash_equals($stored, $input->passwordHash);

        if ($rehashed) {
            // Past the model, as ImportUser writes it (I-2009).
            DB::table('users')->where('id', $user->id)->update(['password' => $input->passwordHash]);
        }

        if ($shown) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::PERSON, (string) $user->id, Footprints::person($user)));
        }

        $followed = $input->role === null ? $this->suspend($user, $input) : $this->lower($user, $input);

        return $followed || $shown || $rehashed;
    }

    /**
     * Suspends a person WordPress now gives no role Teme imports, by
     * SuspendUser's rules: never the last active administrator, their
     * sessions ended, the suspension logged. An account already suspended
     * is left as it is; nothing here ever reactivates one.
     */
    private function suspend(User $user, UpdateImportedUserInput $input): bool
    {
        if ($user->suspended_at !== null) {
            return false;
        }

        try {
            $this->ensureAnotherAdministratorRemains($user);
        } catch (ValidationException) {
            // The last active administrator stays active; the import reports the difference.
            return false;
        }

        $user->suspended_at = now();
        $user->save();
        AccountSessions::endAllFor($user);

        app(SecurityLog::class)->record(SecurityEventType::UserSuspended, $user->id, context: [
            'by' => $input->actorId() === null ? 'operator' : (int) $input->actorId(),
        ]);

        return true;
    }

    /**
     * Gives the person WordPress's role when it is lower than theirs here,
     * by ChangeUserRole's rules: never the last active administrator, their
     * sessions ended, the change logged. A higher role is never applied.
     */
    private function lower(User $user, UpdateImportedUserInput $input): bool
    {
        // Roles::LABELS lists the most powerful first.
        $rank = array_flip(array_keys(Roles::LABELS));
        $from = $user->getRoleNames()->first();

        if ($input->role === null || ! is_string($from) || ! isset($rank[$from]) || $rank[$input->role] <= $rank[$from]) {
            return false;
        }

        try {
            $this->ensureAnotherAdministratorRemains($user);
        } catch (ValidationException) {
            // The last active administrator keeps the role; the import reports the difference.
            return false;
        }

        $user->syncRoles([$input->role]);
        AccountSessions::endAllFor($user);

        app(SecurityLog::class)->record(SecurityEventType::RoleChanged, $user->id, context: [
            'from' => $from,
            'to' => $input->role,
            'by' => $input->actorId() === null ? 'operator' : (int) $input->actorId(),
        ]);

        return true;
    }
}
