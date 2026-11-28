<?php

/**
 * Choosing the plugin that fills a provider slot, or none to leave core's
 * default in place. A null actor is the operator at the server's shell.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ChooseSlotProviderInput implements OperationInput
{
    public function __construct(
        public string $slot,
        public ?string $plugin,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
