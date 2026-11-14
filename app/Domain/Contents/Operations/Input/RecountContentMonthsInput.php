<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * A request to count the month rows of content_counts again, and who asked
 * (I-3131): the administrator who changed the time zone, or the operator.
 */
final readonly class RecountContentMonthsInput implements OperationInput
{
    public function __construct(public ?string $actorId) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
