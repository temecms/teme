<?php

/**
 * What SwitchFediverse needs: the switch's new value, 'on' or 'off', the
 * modules group's version the page was opened on, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class SwitchFediverseInput implements OperationInput
{
    public function __construct(
        public string $value,
        public int $expectedVersion,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
