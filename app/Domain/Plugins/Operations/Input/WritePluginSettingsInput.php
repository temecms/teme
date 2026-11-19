<?php

/**
 * What WritePluginSettings needs: the plugin's code, the values it writes to
 * its own settings group by declared key, and the person it writes for.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class WritePluginSettingsInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public string $code,
        public array $values,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
