<?php

declare(strict_types=1);

namespace App\Domain\Users\Operations\Input;

use Carbon\CarbonImmutable;
use SensitiveParameter;
use Teme\Contracts\Operations\OperationInput;

/**
 * What `ImportUser` needs to bring one WordPress account in as a person:
 * their name, address, author slug, biography and Teme role, the WordPress
 * password hash to keep (null for a random one), the date they joined, and
 * who is importing (null for the operator).
 */
final readonly class ImportUserInput implements OperationInput
{
    public function __construct(
        public string $name,
        public string $email,
        public string $slug,
        public ?string $bio,
        public string $role,
        #[SensitiveParameter] public ?string $passwordHash,
        public ?CarbonImmutable $registeredAt,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
