<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What RetryJob needs: the job and who is retrying it.
 */
final readonly class RetryJobInput implements OperationInput
{
    public function __construct(
        public string $jobId,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
