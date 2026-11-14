<?php

declare(strict_types=1);

namespace App\Domain\Security\Operations\Input;

use App\Domain\Security\OldKeys;
use Teme\Contracts\Operations\OperationInput;

/**
 * What ReencryptSecrets needs: the old keys, held in memory only; how many
 * rows a page reads; and who is asking (null for the operator).
 */
final readonly class ReencryptSecretsInput implements OperationInput
{
    public function __construct(
        public OldKeys $oldKeys,
        public int $chunk = 500,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
