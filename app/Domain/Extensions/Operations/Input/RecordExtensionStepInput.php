<?php

/**
 * What RecordExtensionStep needs: the step and its state, a sentence, and,
 * when they change, the releases, what waits for agreement and whether the
 * request runs or waits.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Operations\Input;

use App\Domain\Extensions\RequestStatus;
use App\Domain\Extensions\RequestStep;
use Teme\Contracts\Operations\OperationInput;

final readonly class RecordExtensionStepInput implements OperationInput
{
    /**
     * @param  array<string, mixed>|null  $needs
     */
    public function __construct(
        public string $requestId,
        public RequestStep $step,
        public string $state,
        public ?string $detail = null,
        public ?array $needs = null,
        public ?RequestStatus $status = null,
        public ?string $fromRelease = null,
        public ?string $toRelease = null,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
