<?php

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What `UpdateProfile` needs: whose profile, the new name, email address
 * and bio, and who is asking.
 */
final readonly class UpdateProfileInput implements OperationInput
{
    public function __construct(
        public string $userId,
        public string $name,
        public string $email,
        public ?string $actorId,
        /** The bio to show under their articles; null leaves it as it is, '' clears it. */
        public ?string $bio = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
