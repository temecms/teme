<?php

/**
 * Brings one WordPress account into Teme as a new person, for an import
 * (I-340): their role, their address, and their WordPress password hash as
 * WordPress kept it, so their WordPress password signs them in until the
 * sign-in upgrades it. Only an importer may.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations;

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\ImportedHashes;
use App\Domain\Users\Operations\Input\ImportUserInput;
use App\Domain\Users\Roles;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class ImportUser extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ImportUserInput $input */
        Validator::make(
            ['name' => trim($input->name), 'email' => Str::lower(trim($input->email)), 'slug' => $input->slug, 'bio' => $input->bio, 'role' => $input->role],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
                'slug' => ['required', 'string', 'max:190', 'regex:/^(?:[\p{Ll}\p{Lo}\p{N}]\p{M}*)+(?:-(?:[\p{Ll}\p{Lo}\p{N}]\p{M}*)+)*$/u', Rule::unique('users', 'slug'), Rule::unique('people', 'slug')],
                'bio' => ['nullable', 'string', 'max:1000'],
                'role' => ['required', Rule::in(array_keys(Roles::LABELS))],
            ],
        )->validate();

        if ($input->passwordHash !== null && ImportedHashes::usable($input->passwordHash) === null) {
            throw ValidationException::withMessages(['password' => __('Teme can’t check a password stored this way.')]);
        }
    }

    /**
     * @return array{userId: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ImportUserInput $input */
        $now = now();

        try {
            // Past the model: its hashed cast would hash WordPress's hash a second time (I-2009).
            $id = DB::table('users')->insertGetId([
                'name' => trim($input->name),
                'email' => Str::lower(trim($input->email)),
                'slug' => $input->slug,
                'bio' => $input->bio,
                'password' => $input->passwordHash ?? Hash::make(Str::random(64)),
                'email_verified_at' => $now,
                'created_at' => $input->registeredAt?->utc() ?? $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Taken since the check; the exception's own message carries the hash and the email, so it goes no further.
            throw ValidationException::withMessages(['email' => __('Another account took this email address or author address meanwhile.')]);
        }

        $user = User::query()->findOrFail($id);
        $user->assignRole($input->role);

        app(PathProjection::class)->syncUser($user);

        // A "not found" page may be stored at the author address they take (I-263).
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::PERSON, (string) $user->id, Footprints::person($user)));

        return ['userId' => (string) $user->id];
    }
}
