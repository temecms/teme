<?php

/**
 * What CancelJob needs: the job and who is cancelling it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Jobs\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
