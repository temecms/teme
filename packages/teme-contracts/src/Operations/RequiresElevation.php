<?php

declare(strict_types=1);

namespace Teme\Contracts\Operations;

/**
 * An Operation that sometimes needs a fresh proof of identity before it runs.
 * Declared by the Operation, not the route, so every caller — browser, job,
 * API, AI — meets the same rule. SECURITY.md commitment 12.
 *
 * @since 0.1.0
 */
interface RequiresElevation
{
    /** @since 0.1.0 */
    public function requiresElevation(OperationInput $input): bool;
}
