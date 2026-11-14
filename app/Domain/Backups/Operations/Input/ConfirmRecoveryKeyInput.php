<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use App\Domain\Backups\RecoveryKeyChallenge;
use SensitiveParameter;
use Teme\Contracts\Operations\OperationInput;

/**
 * What ConfirmRecoveryKey needs: the challenge a shown key came with, the two
 * groups as typed, who is asking.
 */
final readonly class ConfirmRecoveryKeyInput implements OperationInput
{
    /**
     * @param  array<int, string>  $groups  position => the group as typed
     */
    public function __construct(
        public RecoveryKeyChallenge $challenge,
        #[SensitiveParameter] private array $groups,
        public ?string $actorId = null,
    ) {}

    /**
     * The groups as typed, by position.
     *
     * @return array<int, string>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['challenge' => $this->challenge, 'groups' => '[hidden]', 'actorId' => $this->actorId];
    }
}
