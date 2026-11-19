<?php

/**
 * What ChangeVisibility needs: the item, Public, Private or Password
 * protected, the password for the last, and who asks.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use SensitiveParameter;
use Teme\Contracts\Operations\OperationInput;

final readonly class ChangeVisibilityInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public string $visibility,
        #[SensitiveParameter] public ?string $password,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
