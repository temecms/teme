<?php

/**
 * What ChangeBrandColour needs: the colour as typed, # and six hex digits
 * or empty for the theme's own, the design group's version the form was
 * opened on, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Design\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ChangeBrandColourInput implements OperationInput
{
    public function __construct(
        public string $colour,
        public int $expectedVersion,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
