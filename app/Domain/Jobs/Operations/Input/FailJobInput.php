<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What FailJob needs: the job, what went wrong and who is reporting.
 */
final readonly class FailJobInput implements OperationInput
{
    public function __construct(
        public string $jobId,
        public string $message,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
