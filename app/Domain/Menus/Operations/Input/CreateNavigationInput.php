<?php

declare(strict_types=1);

namespace App\Domain\Menus\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What CreateNavigation needs: the new menu's name, who is asking, and the
 * site language it is written in (null for the default).
 */
final readonly class CreateNavigationInput implements OperationInput
{
    public function __construct(
        public string $name,
        public string $actorId,
        public ?string $language = null,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
