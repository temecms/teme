<?php

declare(strict_types=1);

namespace App\Domain\Updates\Operations\Input;

use App\Domain\Updates\Requests\UpdateKind;
use Teme\Contracts\Operations\OperationInput;

/**
 * What RequestTemeUpdate needs: the release asked for, whether it is an update or a roll back, and who asks, or null for the operator.
 */
final readonly class RequestTemeUpdateInput implements OperationInput
{
    public function __construct(
        public string $version,
        public UpdateKind $kind,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
