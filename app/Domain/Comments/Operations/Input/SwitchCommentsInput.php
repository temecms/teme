<?php

/**
 * What SwitchComments needs: the switch's new value, 'on' or 'off', the
 * modules group's version the form was opened on, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class SwitchCommentsInput implements OperationInput
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
