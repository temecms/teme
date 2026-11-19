<?php

/**
 * What CreateNavigation needs: the new menu's name, who is asking, and the
 * site language it is written in (null for the default).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Menus\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
