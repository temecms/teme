<?php

/**
 * Changes the actor's own preferences, merged over what they already set.
 * Each value is read as its declared type first — a form's '0', '1' and
 * '400' — then checked; one undeclared key or bad value refuses the whole
 * change. A preference is per person by definition: nothing here can reach
 * anyone else's row.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Preferences\Operations;

use App\Domain\Preferences\Operations\Input\UpdatePreferencesInput;
use App\Domain\Preferences\PreferenceRegistry;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class UpdatePreferences extends BaseOperation
{
    public function __construct(private readonly PreferenceRegistry $registry) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var UpdatePreferencesInput $input */
        $user = User::query()->find($input->actorId());

        if ($user === null || $user->suspended_at !== null) {
            throw new AuthorizationException('Not permitted to change preferences.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UpdatePreferencesInput $input */
        $this->normalised($input);
    }

    protected function perform(OperationInput $input): null
    {
        /** @var UpdatePreferencesInput $input */
        $user = User::query()->lockForUpdate()->findOrFail($input->actorId());

        $user->preferences = [...(array) ($user->preferences ?? []), ...$this->normalised($input)];
        $user->save();

        return null;
    }

    /**
     * The values as their declared types, or a refusal naming the first
     * undeclared key or bad value.
     *
     * @return array<string, mixed>
     */
    private function normalised(UpdatePreferencesInput $input): array
    {
        $values = [];

        foreach ($input->preferences as $key => $value) {
            $definition = $this->registry->get((string) $key);

            if ($definition === null) {
                throw ValidationException::withMessages(["preferences.{$key}" => __('There is no preference called :key.', ['key' => $key])]);
            }

            $normalised = $definition->normalise($value);

            if (! $definition->allows($normalised)) {
                throw ValidationException::withMessages(["preferences.{$key}" => __(':label cannot be set to that.', ['label' => $definition->label])]);
            }

            $values[(string) $key] = $normalised;
        }

        return $values;
    }
}
