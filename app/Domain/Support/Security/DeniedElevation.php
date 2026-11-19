<?php

/**
 * The default. Queue jobs, API and AI tokens never hold a fresh proof of a
 * person's identity, whatever their scope.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Support\Security;

use Teme\Contracts\Operations\OperationInput;

final class DeniedElevation implements Elevation
{
    public function ensure(OperationInput $input): void
    {
        throw new ElevationRequiredException;
    }

    public function isOperator(): bool
    {
        return false;
    }
}
