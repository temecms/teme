<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What QueueReleaseRebuilds needs: nothing. Only the operator runs it, so
 * there is no actor.
 */
final readonly class QueueReleaseRebuildsInput implements OperationInput
{
    public function actorId(): ?string
    {
        return null;
    }
}
