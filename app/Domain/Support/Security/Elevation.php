<?php

/**
 * Whether the current context has a fresh proof of identity for this actor.
 * Bound per context; the default denies. docs/architecture/security.md.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Support\Security;

use Teme\Contracts\Operations\OperationInput;

interface Elevation
{
    /**
     * @throws ElevationRequiredException
     */
    public function ensure(OperationInput $input): void;

    /**
     * Whether this is the trusted operator at the server's shell. Asked of the
     * bound instance, because the binding differs by context.
     */
    public function isOperator(): bool;
}
