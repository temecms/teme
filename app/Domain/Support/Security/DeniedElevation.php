<?php

declare(strict_types=1);

namespace App\Domain\Support\Security;

use Teme\Contracts\Operations\OperationInput;

/**
 * The default. Queue jobs, API and AI tokens never hold a fresh proof of a
 * person's identity, whatever their scope.
 */
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
