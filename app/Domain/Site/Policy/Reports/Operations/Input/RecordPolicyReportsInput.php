<?php

/**
 * What RecordPolicyReports needs: who is asking, null for the operator, how
 * many rows the table keeps, and for how many days.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Policy\Reports\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RecordPolicyReportsInput implements OperationInput
{
    public function __construct(
        public int $keep = 1000,
        public int $days = 30,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
