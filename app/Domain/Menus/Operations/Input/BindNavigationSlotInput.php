<?php

declare(strict_types=1);

namespace App\Domain\Menus\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What BindNavigationSlot needs: the theme, its slot, the menu to show there
 * or null for none, who is asking, and the other site language whose
 * readers see it there (null for the default language's).
 */
final readonly class BindNavigationSlotInput implements OperationInput
{
    public function __construct(
        public string $themeCode,
        public string $slot,
        public ?string $navigationId,
        public string $actorId,
        public ?string $language = null,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
