<?php

/**
 * What `UpdateImportedUser` needs to bring a person an earlier run created
 * up to date: their WordPress name, biography and password hash, the Teme
 * role WordPress's roles give them now (null for none), and who is
 * importing (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use SensitiveParameter;
use Teme\Contracts\Operations\OperationInput;

final readonly class UpdateImportedUserInput implements OperationInput
{
    public function __construct(
        public string $userId,
        public string $name,
        public ?string $bio,
        #[SensitiveParameter] public ?string $passwordHash,
        public ?string $role,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
