<?php

declare(strict_types=1);

namespace App\Domain\Menus\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What PlaceImportedNavigation needs: the theme, its place, the imported menu
 * to show there, and who is importing (null for the operator).
 */
final readonly class PlaceImportedNavigationInput implements OperationInput
{
    public function __construct(
        public string $themeCode,
        public string $slot,
        public string $navigationId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
