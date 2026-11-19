<?php

/**
 * What QueueReleaseRebuilds needs: nothing. Only the operator runs it, so
 * there is no actor.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class QueueReleaseRebuildsInput implements OperationInput
{
    public function actorId(): ?string
    {
        return null;
    }
}
