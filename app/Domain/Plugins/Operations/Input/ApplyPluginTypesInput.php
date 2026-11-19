<?php

/**
 * Applies a plugin's declared types file to the site, as it is enabled or updated.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ApplyPluginTypesInput implements OperationInput
{
    public function __construct(
        public string $name,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
