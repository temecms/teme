<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What CancelJob needs: the job and who is cancelling it.
 */
final readonly class CancelJobInput implements OperationInput
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
