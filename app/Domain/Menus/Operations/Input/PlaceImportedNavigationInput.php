<?php

/**
 * What PlaceImportedNavigation needs: the theme, its place, the imported menu
 * to show there, and who is importing (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Menus\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
