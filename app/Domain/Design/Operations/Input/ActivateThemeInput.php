<?php

declare(strict_types=1);

namespace App\Domain\Design\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What ActivateTheme needs: the theme to draw the site with, by its code,
 * and who activates it.
 */
final readonly class ActivateThemeInput implements OperationInput
{
    public function __construct(
        public string $code,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
