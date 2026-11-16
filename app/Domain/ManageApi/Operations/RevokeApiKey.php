<?php

declare(strict_types=1);

namespace App\Domain\ManageApi\Operations;

use App\Domain\ManageApi\ManageApiAccess;
use App\Domain\ManageApi\Models\ApiKey;
use App\Domain\ManageApi\Operations\Input\RevokeApiKeyInput;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Revokes a management API key: it stops signing in at once and stays in
 * the list, marked revoked (docs/proposals/management-api.md). An
 * administrator's change, after their password. Revoking a revoked key
 * changes nothing.
 */
final class RevokeApiKey extends BaseOperation implements RequiresElevation
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
        // The key is found under its lock, in perform().
    }

    /**
     * @return bool whether the key was live until now
     */
    protected function perform(OperationInput $input): bool
    {
        /** @var RevokeApiKeyInput $input */
        $key = ApiKey::query()->lockForUpdate()->find($input->keyId)
            ?? throw ValidationException::withMessages(['key' => __('This key no longer exists.')]);

        if ($key->revoked_at !== null) {
            return false;
        }

        $key->revoked_at = Date::now();
        $key->save();

        app(SecurityLog::class)->record(SecurityEventType::ApiKeyRevoked, $input->actorId(), context: [
            'key' => $key->id,
            'name' => $key->name,
            'acts_as' => $key->user_id,
        ]);

        return true;
    }
}
