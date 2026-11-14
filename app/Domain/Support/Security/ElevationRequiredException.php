<?php

declare(strict_types=1);

namespace App\Domain\Support\Security;

use RuntimeException;

/**
 * Thrown when a sensitive change is attempted without a fresh proof of
 * identity. The admin turns it into a redirect to the password confirmation
 * page.
 */
final class ElevationRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This change needs a fresh confirmation of your password.');
    }
}
