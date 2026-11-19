<?php

/**
 * Whether the trusted operator — someone at the server's shell, running a
 * teme:* command — is acting. docs/architecture/operations.md, "The operator".
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Support\Security;

final class Operator
{
    public static function isPresent(): bool
    {
        return app(Elevation::class)->isOperator();
    }
}
