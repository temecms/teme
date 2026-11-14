<?php

declare(strict_types=1);

namespace App\Domain\Support\Security;

/**
 * Whether the trusted operator — someone at the server's shell, running a
 * teme:* command — is acting. docs/architecture/operations.md, "The operator".
 */
final class Operator
{
    public static function isPresent(): bool
    {
        return app(Elevation::class)->isOperator();
    }
}
