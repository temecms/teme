<?php

declare(strict_types=1);

namespace App\Domain\Support\Security;

use Teme\Contracts\Operations\OperationInput;

/**
 * Bound only by teme:* console commands. Someone with a shell on the server is
 * already past every check this could make. Study G, S12.
 */
final class OperatorElevation implements Elevation
{
    public function ensure(OperationInput $input): void {}

    public function isOperator(): bool
    {
        return true;
    }
}
