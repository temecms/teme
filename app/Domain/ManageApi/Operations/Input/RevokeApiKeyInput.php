<?php

declare(strict_types=1);

namespace App\Domain\ManageApi\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What RevokeApiKey needs: the key, and who is asking.
 */
final readonly class RevokeApiKeyInput implements OperationInput
{
    public function __construct(
        public string $keyId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
