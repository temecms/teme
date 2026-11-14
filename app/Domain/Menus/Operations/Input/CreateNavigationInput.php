<?php

declare(strict_types=1);

namespace App\Domain\Menus\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What CreateNavigation needs: the new menu's name, and who is asking.
 */
final readonly class CreateNavigationInput implements OperationInput
{
    public function __construct(
        public string $name,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
