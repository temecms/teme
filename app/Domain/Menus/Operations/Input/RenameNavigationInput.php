<?php

/**
 * What RenameNavigation needs: which menu, its new name, who is asking,
 * and its language by tag when that changes too (null leaves it as it is).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Menus\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RenameNavigationInput implements OperationInput
{
    public function __construct(
        public string $id,
        public string $name,
        public string $actorId,
        public ?string $language = null,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
