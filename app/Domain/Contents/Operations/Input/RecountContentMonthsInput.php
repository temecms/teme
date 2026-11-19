<?php

/**
 * A request to count the month rows of content_counts again, and who asked
 * (I-3131): the administrator who changed the time zone, or the operator.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RecountContentMonthsInput implements OperationInput
{
    public function __construct(public ?string $actorId) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
