<?php

declare(strict_types=1);

namespace App\Domain\Comments\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What SwitchComments needs: the switch's new value, 'on' or 'off', the
 * modules group's version the form was opened on, and who is asking.
 */
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
