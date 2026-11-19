<?php

/**
 * What RecordFidelity needs: the run, the post, the revision compared, the
 * share of words lost or none, the message or none, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use App\Domain\Import\Phases\ImportMessage;
use Teme\Contracts\Operations\OperationInput;

final readonly class RecordFidelityInput implements OperationInput
{
    public function __construct(
        public string $runId,
        public string $objectId,
        public string $checked,
        public ?float $lost,
        public ?ImportMessage $message,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
