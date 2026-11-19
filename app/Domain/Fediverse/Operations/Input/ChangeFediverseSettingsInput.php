<?php

/**
 * What ChangeFediverseSettings needs: the fediverse group's values by key,
 * the version the page was opened on, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ChangeFediverseSettingsInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public array $values,
        public int $expectedVersion,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
