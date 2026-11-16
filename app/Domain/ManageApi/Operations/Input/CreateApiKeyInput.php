<?php

declare(strict_types=1);

namespace App\Domain\ManageApi\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What CreateApiKey needs: the key's name, the person it acts as, its
 * abilities, an optional expiry, and who is asking.
 */
final readonly class CreateApiKeyInput implements OperationInput
{
    /**
     * @param  list<string>  $abilities  values of ApiAbility
     * @param  string|null  $expiresAt  an ISO 8601 date and time, or null for no expiry
     */
    public function __construct(
        public string $name,
        public string $userId,
        public array $abilities,
        public ?string $expiresAt,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
