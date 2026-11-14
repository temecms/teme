<?php

declare(strict_types=1);

namespace App\Domain\Menus\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What RenameNavigation needs: which menu, its new name, and who is asking.
 */
final readonly class RenameNavigationInput implements OperationInput
{
    public function __construct(
        public string $id,
        public string $name,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
