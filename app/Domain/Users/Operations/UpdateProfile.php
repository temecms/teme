<?php

/**
 * Changes your own name and email address. Changing the address asks for a
 * fresh password confirmation and marks the new address unverified.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations;

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\Contents\AuthorSort;
use App\Domain\People\PublicProfile;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Operations\Input\UpdateProfileInput;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class UpdateProfile extends BaseOperation implements RequiresElevation
{
    public function requiresElevation(OperationInput $input): bool
    {
        /** @var UpdateProfileInput $input */
        $current = User::query()->whereKey($input->userId)->value('email');

        return $current !== null && Str::lower(trim($input->email)) !== $current;
    }

    protected function authorize(OperationInput $input): void
    {
        /** @var UpdateProfileInput $input */
        if ($input->actorId() === null || $input->actorId() !== $input->userId) {
            throw new AuthorizationException('You may only update your own profile.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UpdateProfileInput $input */
        Validator::make(
            ['name' => trim($input->name), 'email' => $this->email($input), 'bio' => $input->bio === null ? null : trim($input->bio)],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($input->userId)],
                'bio' => ['nullable', 'string', 'max:1000'],
            ],
        )->validate();

        if ($input->publicProfile !== null) {
            $problems = PublicProfile::problems(PublicProfile::clean($input->publicProfile));

            if ($problems !== []) {
                throw ValidationException::withMessages($problems);
            }
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var UpdateProfileInput $input */
        $user = User::query()->lockForUpdate()->findOrFail($input->userId);
        $email = $this->email($input);

        $user->name = trim($input->name);

        // Plain text, which templates escape; null leaves it, '' clears it.
        if ($input->bio !== null) {
            $user->bio = trim($input->bio) === '' ? null : trim($input->bio);
        }

        // A changed address is unproven until the new one is verified — the
        // same rule the starter kit applied.
        if ($user->email !== $email) {
            $user->email = $email;
            $user->email_verified_at = null;
        }

        // Null leaves what they chose to show; an empty form clears it. Stored as one object, never a list.
        if ($input->publicProfile !== null) {
            $clean = PublicProfile::clean($input->publicProfile);
            $user->public_profile = $clean === [] ? null : $clean;
        }

        $renamed = $user->isDirty('name');
        // Their name and bio are shown under their articles and on their
        // page; their email isn't (I-263).
        $shownChanged = $renamed || $user->isDirty('bio') || $user->isDirty('public_profile');
        $user->save();

        // One set-based statement, like ContentCounts::moveAuthor, so the
        // lists' author sort follows the new name however much they wrote.
        // Not through the model: a rename is no change to their content, so
        // updated_at stays.
        if ($renamed) {
            DB::table('contents')->where('author_id', $user->id)->update(['author_sort' => AuthorSort::of($user->name)]);
        }

        app(PathProjection::class)->syncUser($user);

        if ($shownChanged) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::PERSON, (string) $user->id, Footprints::person($user)));
        }

        return null;
    }

    private function email(UpdateProfileInput $input): string
    {
        return Str::lower(trim($input->email));
    }
}
