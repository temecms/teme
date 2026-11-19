<?php

/**
 * Makes a management API key that acts as one person, with the abilities
 * chosen from ApiAbility and an optional expiry (docs/proposals/management-api.md).
 * An administrator's change, after their password; the secret is returned
 * this once and only its hash is kept.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\ManageApi\Operations;

use App\Domain\ManageApi\ApiAbility;
use App\Domain\ManageApi\ApiKeys;
use App\Domain\ManageApi\ManageApiAccess;
use App\Domain\ManageApi\Models\ApiKey;
use App\Domain\ManageApi\NewApiKey;
use App\Domain\ManageApi\Operations\Input\CreateApiKeyInput;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class CreateApiKey extends BaseOperation implements RequiresElevation
{
    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function authorize(OperationInput $input): void
    {
        $actor = app(Actors::class)->find($input->actorId());

        if ($actor === null || ! $actor->can(ManageApiAccess::ABILITY)) {
            throw new AuthorizationException('Not permitted to manage API keys.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var CreateApiKeyInput $input */
        Validator::make(
            [
                'name' => trim($input->name),
                'user' => $input->userId,
                'abilities' => $input->abilities,
                'expires_at' => $input->expiresAt,
            ],
            [
                'name' => ['required', 'string', 'max:100'],
                'user' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('suspended_at')],
                'abilities' => ['required', 'array', 'min:1'],
                'abilities.*' => ['string', 'distinct', Rule::in(ApiAbility::values())],
                'expires_at' => ['nullable', 'date', 'after:now'],
            ],
            [],
            [
                'name' => __('name'),
                'user' => __('person'),
                'abilities' => __('abilities'),
                'expires_at' => __('expiry'),
            ],
        )->validate();
    }

    protected function perform(OperationInput $input): NewApiKey
    {
        /** @var CreateApiKeyInput $input */
        $name = trim($input->name);

        // One maker of a name at a time, so the check below and the insert agree.
        DB::select("SELECT pg_advisory_xact_lock(hashtext('teme.api_keys.' || lower(?)))", [$name]);

        if (ApiKey::query()->whereNull('revoked_at')->whereRaw('lower(name) = lower(?)', [$name])->exists()) {
            throw ValidationException::withMessages(['name' => __('A key with this name already exists. Choose another name.')]);
        }

        $secret = ApiKeys::newSecret();
        $abilities = array_values(array_filter(
            ApiAbility::values(),
            fn (string $ability): bool => in_array($ability, $input->abilities, true),
        ));

        $key = ApiKey::query()->create([
            'name' => $name,
            'user_id' => (int) $input->userId,
            'abilities' => $abilities,
            'token_hash' => ApiKeys::hash($secret),
            'token_start' => substr($secret, 0, ApiKeys::START),
            'expires_at' => $input->expiresAt === null ? null : Date::parse($input->expiresAt),
            'created_by' => $input->actorId() === null ? null : (int) $input->actorId(),
        ]);

        app(SecurityLog::class)->record(SecurityEventType::ApiKeyCreated, $input->actorId(), context: [
            'key' => $key->id,
            'name' => $name,
            'acts_as' => (int) $input->userId,
            'abilities' => $abilities,
        ]);

        return new NewApiKey($key, $secret);
    }
}
