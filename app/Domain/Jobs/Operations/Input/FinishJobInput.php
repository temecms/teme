<?php

/**
 * What FinishJob needs: the job, its closing words and who is reporting.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Jobs\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class FinishJobInput implements OperationInput
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
