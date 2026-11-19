<?php

/**
 * What ReportJobProgress needs: the job, the steps done since the last report, a total if it changed, the latest words and who is reporting.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Jobs\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ReportJobProgressInput implements OperationInput
{
    public function __construct(
        public string $jobId,
        public int $advancedBy = 0,
        public ?int $total = null,
        public ?string $message = null,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
