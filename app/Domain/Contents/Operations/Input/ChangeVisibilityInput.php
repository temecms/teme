<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use SensitiveParameter;
use Teme\Contracts\Operations\OperationInput;

/**
 * What ChangeVisibility needs: the item, Public, Private or Password
 * protected, the password for the last, and who asks.
 */
final readonly class ChangeVisibilityInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public string $visibility,
        #[SensitiveParameter] public ?string $password,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
