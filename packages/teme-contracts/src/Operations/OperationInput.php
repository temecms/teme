<?php

declare(strict_types=1);

namespace Teme\Contracts\Operations;

/**
 * Marker for a typed, immutable input to an Operation.
 *
 * Implementations are readonly classes carrying exactly the data the
 * operation needs, including the acting user's identifier.
 *
 * @since 0.1.0
 */
interface OperationInput
{
    /**
     * Returns the acting person's ID, or null for the operator.
     *
     * @since 0.1.0
     */
    public function actorId(): ?string;
}
