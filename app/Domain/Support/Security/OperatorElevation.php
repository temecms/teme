<?php

/**
 * Bound only by teme:* console commands. Someone with a shell on the server is
 * already past every check this could make. Study G, S12.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Support\Security;

use Teme\Contracts\Operations\OperationInput;

final class OperatorElevation implements Elevation
{
    public function ensure(OperationInput $input): void {}

    public function isOperator(): bool
    {
        return true;
    }
}
