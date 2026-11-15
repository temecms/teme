<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing\Operations\Input;

use App\Domain\Fediverse\Publishing\DeliveryOutcome;
use Teme\Contracts\Operations\OperationInput;

/**
 * What RecordDelivery needs: the delivery, and what its try came to.
 */
final readonly class RecordDeliveryInput implements OperationInput
{
    public function __construct(
        public string $deliveryId,
        public DeliveryOutcome $outcome,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
