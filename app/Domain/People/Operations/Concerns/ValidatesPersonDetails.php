<?php

/**
 * The rules a person with no account's details follow, and who may be
 * changed this way (I-3366): a name, an
 * address slug as an account's is made, free among accounts and people and
 * at an address no one else holds, and a short biography.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\People\Operations\Concerns;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\Paths;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait ValidatesPersonDetails
{
    /**
     * Refuses what is given and breaks a rule; null is left as it is. $self
     * is the person being changed, whose own slug and address are free.
     */
    private function checkDetails(?string $name, ?string $slug, ?string $bio, ?string $self = null): void
    {
        Validator::make(
            array_filter(['name' => $name === null ? null : trim($name), 'slug' => $slug, 'bio' => $bio === null ? null : trim($bio)], fn (?string $value): bool => $value !== null),
            [
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'slug' => ['sometimes', 'required', 'string', 'max:190', 'regex:/^(?:[\p{Ll}\p{Lo}\p{N}]\p{M}*)+(?:-(?:[\p{Ll}\p{Lo}\p{N}]\p{M}*)+)*$/u'],
                'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ],
            [
                'name.required' => __('Give the person a name.'),
                'slug.required' => __('Use lower-case letters, numbers and hyphens.'),
                'slug.regex' => __('Use lower-case letters, numbers and hyphens.'),
            ],
        )->validate();

        if ($slug !== null) {
            $this->checkSlug($slug, $self);
        }
    }

    /**
     * Refuses a slug an account or another person has, or whose address
     * another target holds.
     */
    private function checkSlug(string $slug, ?string $self): void
    {
        $taken = DB::table('users')->where('slug', $slug)->exists()
            || DB::table('people')->where('slug', $slug)->when($self !== null, fn ($query) => $query->where('id', '<>', $self))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['slug' => __('Someone already has the address :address.', ['address' => Addresses::toUrl(Addresses::forPerson($slug))])]);
        }

        $clash = Paths::conflict(Addresses::forPerson($slug), 'person', $self ?? '');

        if ($clash !== null) {
            throw ValidationException::withMessages(['slug' => $clash]);
        }
    }

    /**
     * Refuses a person who doesn't exist, or whose details are an account's.
     */
    private static function refuseAccount(string $personId, ?object $person = null): void
    {
        $person ??= DB::table('people')->where('id', $personId)->first(['user_id']);

        if ($person === null) {
            throw ValidationException::withMessages(['person' => __("There's no such person: :id.", ['id' => $personId])]);
        }

        if ($person->user_id !== null) {
            throw ValidationException::withMessages(['person' => __("Their name, address and biography come from their account's profile.")]);
        }
    }
}
