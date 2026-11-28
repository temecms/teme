<?php

/**
 * What ChangePluginSecret needs: the plugin's code, the free key, the value
 * to keep or null to remove it, and the person it changes the secret for.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ChangePluginSecretInput implements OperationInput
{
    public function __construct(
        public string $code,
        public string $key,
        public ?string $value,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
