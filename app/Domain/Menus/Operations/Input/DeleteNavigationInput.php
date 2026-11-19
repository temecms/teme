<?php

/**
 * What DeleteNavigation needs: which menu, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Menus\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class DeleteNavigationInput implements OperationInput
{
    public function __construct(
        public string $id,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
